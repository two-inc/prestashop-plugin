/**
 * Two Company Search Module - Clean, focused company selection
 * Handles company autocomplete, organization number persistence, and address saving
 */

/**
 * Shortest term the company search will act on (TWO-25288).
 *
 * The ONE place this number is written. It gates the request on both render
 * paths AND is interpolated into the hint the buyer reads, so the number the
 * hint claims and the number the code enforces cannot drift apart - which is
 * why the translatable string in twopayment.php carries an unresolved `%d`
 * rather than a spelled-out "3". Do not reintroduce a literal here, in the
 * widget options, or in the translation catalogues.
 */
const MIN_SEARCH_LENGTH = 3;

class TwoCompanySearch {
    static DEFAULT_COMPANY_SEARCH_LIMIT = 50;

    // Records the exact value an address autofill wrote into a field, so a
    // later fill can tell "we put this here" from "the buyer typed this". The
    // buyer editing the field leaves the attribute stale rather than matching,
    // which is the signal we need - see autoFillAddress().
    //
    // It lives on the DOM node, so PrestaShop replacing the address form on
    // `updatedAddressForm` takes it with the node. A value that survives that
    // re-render therefore reads as buyer input to the next fill and is left
    // alone. That is the residual case, and it errs the right way: the cost is
    // one company's city outliving its selection across a re-render, against
    // blanking an answer the buyer gave us.
    static AUTOFILL_MARKER_ATTR = 'data-two-autofilled-value';

    // THERE IS NO "hide the identification field" MARKER HERE, AND MUST NOT BE
    // ONE (TWO-40, Doug's ruling, Option A). An internal (`TWO:`-prefixed)
    // identifier is never written into the visible `dni` field in the first
    // place, so there is nothing to hide. A hiding rule was tried and removed:
    // it could never have been complete, because core renders `dni` into address
    // blocks, invoice PDFs and order emails through
    // AddressFormat::generateAddress(), which no CSS rule of ours reaches. See
    // writeOrganizationToAddressIdentifiers().

    /**
     * Company-search result cache, held on the CLASS rather than inside
     * setupAutocomplete().
     *
     * TwoCheckoutManager.handleAddressFormUpdate() destroys and re-creates this
     * widget on every `prestashop.on('updatedAddressForm')`, and PrestaShop
     * fires that for ordinary interactions such as changing country. A cache
     * owned by the setupAutocomplete() closure was therefore thrown away and
     * started cold after every one of those re-renders, so the buyer re-paid a
     * full API round trip for a term they had already searched moments earlier.
     * Class scope outlives any individual instance, so the cache survives
     * teardown.
     *
     * Aborting the in-flight request on teardown remains correct and is
     * unchanged: a response whose widget no longer exists has nowhere to
     * render. Only the cache is preserved, not the pending request.
     */
    static _resultCache = new Map();

    /**
     * Deadline (epoch ms) up to which a freshly built panel should reopen
     * itself, because the panel it replaces was open when PrestaShop
     * re-rendered the address form out from under the buyer.
     *
     * On the CLASS, and a deadline rather than a boolean, for two reasons.
     *
     * A single `updatedAddressForm` tears the control down TWICE: this
     * module's own handler closes and rebuilds the panel on the SAME instance,
     * and then TwoCheckoutManager destroy()s that instance and constructs a
     * replacement. Instance state cannot cross the second of those, and a flag
     * consumed by the first rebuild would be gone before the one the buyer
     * actually ends up looking at.
     *
     * A deadline also fails safe. Nothing has to remember to clear it: if the
     * rebuild never comes - the buyer left the step, the module was torn down
     * for good - it simply expires, and the worst case is a panel that does
     * not reopen. A boolean left set would reopen an unrelated panel the next
     * time one happened to be built.
     */
    static _reopenPanelUntil = 0;

    /** How long after a re-render a rebuilt panel may restore itself. */
    static _REOPEN_WINDOW_MS = 1500;
    // A company registered mid-session stays absent from an already-searched
    // term until its entry expires. That staleness is deliberate: buyers search
    // for their own company, which is already registered, so nothing here busts
    // the cache on registration - the TTL below is the only thing that clears
    // it, and a page reload starts cold.
    static _CACHE_TTL_MS = 5 * 60 * 1000;
    // Bounds the cache. It now lives for the whole page session rather than
    // until the next address-form re-render, so it needs an eviction policy it
    // did not need before.
    static _CACHE_MAX_ENTRIES = 50;

    /**
     * Read a still-live cache entry, or null. Expired entries drop on read.
     *
     * @param {string} key
     * @returns {Array|null}
     */
    static cacheGet(key) {
        const entry = TwoCompanySearch._resultCache.get(key);
        if (!entry) {
            return null;
        }
        if ((Date.now() - entry.t) >= TwoCompanySearch._CACHE_TTL_MS) {
            TwoCompanySearch._resultCache.delete(key);
            return null;
        }
        return entry.v;
    }

    /**
     * Store a result set. Only ever called for a completed search - a cached
     * failure would keep showing the buyer an error after the service recovered.
     *
     * @param {string} key
     * @param {Array} value
     */
    static cacheSet(key, value) {
        const cache = TwoCompanySearch._resultCache;
        const cutoff = Date.now() - TwoCompanySearch._CACHE_TTL_MS;
        cache.forEach((entry, cachedKey) => {
            if (entry.t <= cutoff) {
                cache.delete(cachedKey);
            }
        });
        // Map iterates in insertion order, so the first key is the oldest.
        while (cache.size >= TwoCompanySearch._CACHE_MAX_ENTRIES) {
            const oldest = cache.keys().next();
            if (oldest.done) {
                break;
            }
            cache.delete(oldest.value);
        }
        cache.set(key, { v: value, t: Date.now() });
    }

    constructor(config) {
        this.config = {
            companyFieldSelector: "input[name='company']",
            checkoutHost: '',
            saveCompanyUrl: '',
            // Page size for GET /companies/v2/company. Matches the Magento
            // (`companySearchLimit` in Model/Ui/ConfigProvider.php) and
            // WooCommerce (`twoincSearchLimit` in assets/js/twoinc.js)
            // plugins. Without it the API's own default decides how many
            // rows come back, so a common name in a large country gives the
            // buyer an unbounded list. Like both of those plugins there is
            // no load-more UI - the first page is the whole result set.
            companySearchLimit: TwoCompanySearch.DEFAULT_COMPANY_SEARCH_LIMIT,
            // Merchant toggle for the address lookup (TWO-25203). Default-on,
            // mirroring the server-side resolver: the fill was unconditional
            // before the toggle existed, so an omitted value keeps it on.
            addressLookupEnabled: true,
            ...config
        };
        
        this.companyField = null;
        this.organizationField = null;
        this.isInitialized = false;
        this.countryListener = null;

        // Race-condition guards for company search (see searchCompanies())
        this._companySearchSeq = 0;
        this._companySearchXhr = null;

        // Set by destroy(). Every entry point reachable from an event checks it,
        // so make it explicit rather than relying on undefined being falsy.
        this._destroyed = false;
        // Pending retry from setupCountryChangeListener(), cleared on destroy.
        this._countryRetryTimeoutId = null;

        // Manual-entry mode (TWO-25288). Set by the "my company is not on the
        // list" row in the dropdown; while it is on, neither render path opens a
        // dropdown at all, so the buyer types the company name into the field
        // without a suggestion list arguing with them. The reverse link below
        // the field turns it back off.
        this._manualEntry = false;
        this._backToSearchLink = null;
        // "Select a different sole trader" reverse link (TWO-40 follow-up) -
        // same shape as `_backToSearchLink` above, for a completed
        // sole-trader enrolment instead of manual entry.
        this._selectDifferentSoleTraderLink = null;

        // The anchored dropdown panel (TWO-25326 §1). Supersedes the
        // click-to-reveal chip TWO-25288 element 2 shipped.
        //
        // That chip existed for one stated reason: "this field has no split -
        // the address form's own `input[name='company']` IS the search box",
        // so it stood in for select2's `.select2-selection__rendered` by
        // covering the field to stop typing overwriting a confirmed name.
        // TWO-25326 §1 requires the split itself - a real popup control
        // anchored to the field, carrying its OWN query input, with the
        // company-name field left untouched until a result is picked. Once
        // that exists the chip is not merely redundant, it is contradictory:
        // its whole behaviour is to blank the company-name field on click,
        // which is exactly what §1 forbids. So it is gone, and this panel is
        // what replaces it.
        this._dropdown = null;
        this._queryField = null;
        // The three-chip mode selector (TWO-40 design revision). See
        // buildDropdown() for the DOM shape and bindDropdownHandlers() for
        // what each chip's click does.
        this._notListedButton = null;
        this._soleTraderButton = null;
        this._registeredButton = null;
        // Which chip is current - drives the `--selected` class. Reset to the
        // default on every openDropdown() (see there).
        this._chipMode = 'registered';
        this._resultsList = null;
        this._dropdownOpen = false;
        // Deferred close, so focus moving BETWEEN two controls inside the
        // panel (query field -> "not on the list") does not read as leaving
        // it. See scheduleDropdownClose().
        this._closeTimerId = null;
        // True between a mousedown on the panel and the matching mouseup -
        // a scrollbar drag, chiefly. See bindDropdownKeyboard().
        this._pointerInPanel = false;
        // Set while the results area's height is pinned for the duration of a
        // pointer press, so the "not on the list" button cannot slide out from
        // under the pointer between mousedown and mouseup. See
        // freezeResultsHeight().
        this._resultsHeightFrozen = false;
        this._resultsFreezeReleaseId = null;
        // True between beginSoleTraderLoading() and endSoleTraderLoading() -
        // the panel is being kept open with the query-field spinner showing
        // for a Sole Trader click's autofill round trip (TWO-40 round 4).
        this._soleTraderLoading = false;
        // Re-entrancy guard for the "Select a different sole trader" link's
        // click handler (TWO-40 follow-up) - same shape as
        // `_soleTraderLoading` above, released on the same settle event, but
        // its own flag/namespace since the two buttons/flows are independent.
        this._selectDifferentSoleTraderLoading = false;
        // Per-instance event namespace suffix. The `mouseup` guard has to be
        // bound on `document` (a drag can end anywhere, including outside the
        // panel), and `document` is a page-wide singleton - so unbinding by
        // the shared `.twoDropdown` namespace alone would tear off another
        // live instance's handler too. Same reasoning as the by-reference
        // unbind on the `window` resize listener below.
        TwoCompanySearch._instanceSeq = (TwoCompanySearch._instanceSeq || 0) + 1;
        this._instanceNs = 'i' + TwoCompanySearch._instanceSeq;

        this.init();
    }
    
    /**
     * Initialize the company search functionality
     */
    init() {
        this.companyField = $(this.config.companyFieldSelector);
        
        if (this.companyField.length === 0) {
            return;
        }
        
        this.createOrganizationField();
        this.ensureFieldWrapper();
        this.createCompanyIdHintField();
        this.clearStaleOrganizationSelection();
        this.setupCompanyInputSync();
        this.setupAddressIdentifierSync();
        this.setupAutocomplete();
        this.setupCountryChangeListener();
        this.mirrorConfirmedCompanyToInvoiceAddress();
        // No visibility pass over the identification field here. Nothing this class
        // writes can put an internal (`TWO:`) identifier into it (see
        // writeOrganizationToAddressIdentifiers), and a value the SERVER rendered
        // there is the buyer's own fiscal number, which is theirs to see and edit.
        this.isInitialized = true;
    }
    
    /**
     * The marker class the `.ui-autocomplete` this field's widget builds gets,
     * so the CSS below can clamp THIS field's dropdown without also clamping
     * an unrelated jQuery UI autocomplete elsewhere on the same page (a native
     * PrestaShop lookup, another module) to whatever width this field
     * happens to be. `.ui-autocomplete` is jQuery UI's own un-namespaced
     * default class - reviewed and confirmed live-review finding, TWO-30.x.10.
     */
    static AUTOCOMPLETE_MENU_CLASS = 'two-company-autocomplete-menu';

    /**
     * Publish the company field's current width as a CSS custom property, so
     * `.ui-autocomplete.two-company-autocomplete-menu`'s `max-width`
     * (views/css/two.css) can clamp jQuery UI's own dropdown to it
     * (TWO-30.x.10 element 1).
     *
     * Set on `document.documentElement` rather than on the field or its
     * wrapper: the menu is appended by jQuery UI to `<body>`, not nested
     * inside this field's own markup, so a variable set anywhere under the
     * field would not inherit down to it. A custom property inherits from
     * any ancestor in the real DOM tree, and `<html>` is common to both.
     * Reached through `element.style.setProperty()` rather than jQuery's
     * `.css()` deliberately - jQuery's setter does its own property-name
     * normalisation, which is not guaranteed to pass a custom property
     * through unmangled across jQuery versions.
     *
     * VESTIGIAL as of TWO-25326, kept because it is cheap and harmless. Its
     * purpose was to clamp a menu jQuery UI appended to `<body>`, where
     * nothing else could size it. The menu now renders inside a panel that is
     * itself pinned to the field's width, and the stylesheet sets
     * `max-width: none !important` on it - so this variable can no longer
     * affect the dropdown. It is still cleared in destroy() for the reason it
     * always was: it is a page-wide singleton, and a stale value must not
     * outlive the field that set it.
     *
     * A single shared variable is a page-wide singleton, matching every
     * other assumption already documented in this class (see
     * buildDropdown()) about there being one live company field at a
     * time. Cleared on a falsy width and in destroy() (both TWO-30.x.10
     * review findings) precisely because it IS a singleton: a stale value
     * left behind by a field that has since gone hidden or been torn down
     * must not silently keep clamping whatever field reads it next.
     */
    constrainAutocompleteMenuWidth() {
        if (!this.companyField || !this.companyField.length) {
            return;
        }
        const width = this.companyField.outerWidth();
        if (width) {
            document.documentElement.style.setProperty('--two-company-search-width', width + 'px');
        } else {
            document.documentElement.style.removeProperty('--two-company-search-width');
        }
    }

    /**
     * Create or ensure organization number field exists
     */
    createOrganizationField() {
        let orgField = $("input[name='companyid']");
        
        if (orgField.length === 0) {
            orgField = $('<input type="hidden" name="companyid" value="">');
            this.companyField.after(orgField);
        }
        
        this.organizationField = orgField;
    }

    /**
     * Wrap the company field in a tight-fitting positioned span, idempotently.
     *
     * TWO-30.x.10 element 2/3: the org-number hint used to position itself
     * absolutely against `this.companyField.parent()` - the
     * field's THEME wrapper (a Bootstrap `.form-group`/column div), which
     * commonly carries its own padding and therefore has a different width
     * and left offset than the input it contains. A chip or hint positioned
     * `inset: 0` / `right: 0` against THAT box renders wider than the field
     * and offset from its edge, rather than matching it - exactly the
     * too-wide result field and the occluded org-number hint Doug found live.
     *
     * A dedicated wrapper hugging only the input removes the dependency on
     * whatever padding a theme's own wrapper happens to carry: a plain
     * block-level element with no padding of its own stretches to the same
     * width as the input it wraps, whatever that width is. This mirrors what
     * select2/selectWoo already do on the Woo/Magento side - they replace the
     * plain `<select>` with their own tightly-fitting container rather than
     * positioning against the field's outer form markup.
     *
     * Re-run on every setupAutocomplete() call (not only init()), because
     * that method re-resolves `this.companyField` against whatever node
     * PrestaShop just put on the page on `updatedAddressForm` - a fresh node
     * has no wrapper of ours yet.
     *
     * The wrapper's width is ALSO pinned explicitly here, in JS, on every
     * call - not left to CSS block auto-sizing alone (TWO-30.x.10 review
     * finding, Han + Vader convergent). A `display:block`/`inline-block` span
     * with no padding of its own only ends up the SAME width as the input it
     * wraps when the input already fills 100% of its container; on a theme
     * where the field has its own narrower intrinsic width (a `size=`
     * attribute, a non-Bootstrap layout, an input-group addon) that
     * assumption silently fails and reproduces this PR's own bug one DOM
     * level lower. Pinning the width directly to the input's own
     * `outerWidth()` removes the assumption entirely, whatever the
     * containing layout does.
     */
    ensureFieldWrapper() {
        if (!this.companyField || !this.companyField.length) {
            return;
        }
        let wrapper = this.companyField.parent();
        if (!wrapper.length || !wrapper.hasClass('two-company-field-wrap')) {
            this.companyField.wrap('<span class="two-company-field-wrap"></span>');
            wrapper = this.companyField.parent();
        }
        // TWO-25326 bug 10: RELEASE any width this method pinned on a previous
        // call before measuring, or the pin latches and the control never
        // follows the viewport again.
        //
        // The input is a theme `.form-control`, i.e. `width: 100%` of its
        // container - and after the first call that container IS this wrapper,
        // at a fixed pixel width. So `outerWidth()` stops measuring the layout
        // and starts reading back the value pinned last time: the resize
        // listener below re-runs this method on every viewport change, measures
        // the same stale number, and re-pins it. That is why the optional
        // fields (pure CSS, no JS width anywhere) reflow on resize and the
        // company control does not.
        //
        // Released and re-measured rather than simply not pinned: the pin is
        // still load-bearing on themes where the input has its own narrower
        // intrinsic width (see this method's comment above). With the pin off
        // for the duration of the measurement, the wrapper is back to being an
        // auto-width block and the input measures against the real layout
        // again.
        const wrapperElement = wrapper.get(0);
        if (wrapperElement && wrapperElement.style.width) {
            wrapper.css('width', '');
        }
        // Anchor height for the dropdown panel, refreshed alongside the width
        // for the same reason: the panel is positioned against the INPUT, not
        // against the wrapper, which also carries the org-number label.
        const height = this.companyField.outerHeight();
        if (height) {
            wrapper.get(0).style.setProperty('--two-company-input-height', height + 'px');
        } else {
            wrapper.get(0).style.removeProperty('--two-company-input-height');
        }
        const width = this.companyField.outerWidth();
        if (width) {
            wrapper.css('width', width + 'px');
        } else {
            // Cleared, not left stale: a wrapper created while the field was
            // momentarily hidden/detached must not keep a previous, possibly
            // wrong, pixel width once the field is measurable again - same
            // staleness hazard as the CSS variable in
            // constrainAutocompleteMenuWidth(), same fix.
            wrapper.css('width', '');
        }
    }

    /**
     * Keep the wrapper width and the dropdown-clamp CSS variable current
     * across a viewport change, not only on the next keystroke (TWO-30.x.10
     * review finding, Han + Vader convergent).
     *
     * Both are otherwise only refreshed from the `source` callback, i.e. once
     * per search. A buyer who rotates a tablet or resizes the window while
     * the dropdown is already open, without typing again first, would
     * otherwise see the wrapper/dropdown drift out of sync with the field
     * until their next keystroke corrects it.
     *
     * Bound at most once per instance (`_widthRefreshBound` guards it): this
     * method is called from setupAutocomplete(), which itself re-runs on
     * every country change and address-form update, and `window` has no
     * per-listener identity check the way jQuery delegation on a document
     * node does - a second `.on('resize.twoCompanyWidth', ...)` call does
     * not replace the first, it stacks.
     *
     * Unbound in destroy() by FUNCTION REFERENCE
     * (`$(window).off(events, this._widthRefreshHandler)`), not by namespace
     * alone (round-2 review finding, Vader). `window` is a genuine
     * page-wide singleton, unlike the per-node sibling sweeps this file uses
     * elsewhere (see buildDropdown()/removeDropdown()) - a namespace-only
     * `.off('.twoCompanyWidth')` would remove every instance's handler under
     * that name, not just the one being destroyed. Not reachable today
     * (TwoCheckoutManager.handleAddressFormUpdate() destroys the old instance
     * and constructs the new one synchronously, so there is never a moment
     * with two live instances of this listener at once), but binding/
     * unbinding by reference costs nothing and removes the landmine outright
     * rather than relying on that invariant holding forever.
     */
    setupWidthRefreshListener() {
        if (this._widthRefreshBound) {
            return;
        }
        this._widthRefreshBound = true;
        this._widthRefreshHandler = () => {
            if (this._destroyed) {
                return;
            }
            clearTimeout(this._widthRefreshTimeoutId);
            this._widthRefreshTimeoutId = setTimeout(() => {
                if (this._destroyed) {
                    return;
                }
                this.ensureFieldWrapper();
                this.constrainAutocompleteMenuWidth();
            }, 150);
        };
        $(window).on('resize.twoCompanyWidth orientationchange.twoCompanyWidth', this._widthRefreshHandler);
    }

    /**
     * Create or ensure the inline "selected company's org number" hint span
     * exists next to the company field (TWO-25288). Grey, informational
     * only - never a form field, never submitted.
     */
    createCompanyIdHintField() {
        let hintField = $('.two-company-id-hint');

        if (hintField.length === 0) {
            hintField = $('<span class="two-company-id-hint"></span>');
            this.companyField.after(hintField);
            // The hint sits in NORMAL FLOW inside `.two-company-field-wrap`,
            // immediately after the input (see two.css). It used to be
            // absolutely positioned at `top: 100%`, which is what let it
            // paint over the VAT-number field below - TWO-25326 §5. Nothing
            // to position here: an in-flow block takes its own height and
            // right-aligns to the wrapper's edge, and the wrapper already
            // matches the input's width exactly.
        }

        this.companyIdHintField = hintField;
    }

    /**
     * Show or clear the inline org-number hint. Called on selection and on
     * every path that clears the hidden companyid field, so the two never
     * drift apart.
     *
     * @param {string} [value]
     */
    setCompanyIdHint(value) {
        if (this.companyIdHintField && this.companyIdHintField.length) {
            // TWO-25326 §12: a `TWO:`-prefixed number is an internal
            // identifier and is never shown - forDisplay() answers '' for it,
            // which the existing empty-string handling below already treats as
            // "no label at all" (no text, no reserved line box), so the
            // suppressed case needs no branch of its own here.
            const text = window.TwoCompanyNumber.forDisplay(value);
            this.companyIdHintField.text(text);
            // TWO-25326 §5/§7: the label takes space in normal flow now, so
            // an EMPTY one still occupies a line box and adds height to an
            // address form that should look identical to every other row
            // until a company is actually selected. Toggling the class - not
            // just the text - is what keeps "additional space only when the
            // company number is visible" true.
            this.companyIdHintField.toggleClass('two-company-id-hint--visible', text !== '');
        }
    }

    /**
     * @returns {string} accessible name for the company-name field while it
     *   acts as the trigger that opens the search panel (TWO-25326 §1). Its
     *   visible value is the confirmed company name; this says what
     *   activating it does.
     */
    getEditCompanyText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_edit)
            || 'Search for a different company';
    }

    /**
     * Whether the field currently holds a company the buyer actually PICKED,
     * as opposed to a name sitting next to a stale or absent organisation
     * number. Deliberately the same test clearStaleOrganizationSelection()
     * already uses - the file must have exactly one notion of "confirmed",
     * not a second one that can quietly drift from it.
     *
     * @returns {boolean}
     */
    hasConfirmedSelection() {
        if (!this.companyField || !this.companyField.length
            || !this.organizationField || !this.organizationField.length) {
            return false;
        }
        const company = String(this.companyField.val() || '').trim();
        const orgNumber = String(this.organizationField.val() || '').trim();
        const tag = String(this.organizationField.attr('data-two-company-name') || '').trim();
        if (!company || !orgNumber || !tag) {
            return false;
        }
        return this.normalizeCompanyName(company) === this.normalizeCompanyName(tag);
    }

    /**
     * Whether the buyer is currently in "My company is not on the list" mode,
     * typing a company name directly rather than picking one from search
     * results. TwoCheckoutManager's tile-mode order-intent gate
     * (canAutoTriggerOrderIntent()) treats this as a selection too - the
     * buyer has made their choice, just not through the search results.
     *
     * @returns {boolean}
     */
    isManualEntry() {
        return !!this._manualEntry;
    }

    /**
     * Build the anchored dropdown panel, idempotently (TWO-25326 §1/§2).
     *
     * DOM ORDER IS THE DESIGN HERE, not an implementation detail. Every part
     * lives inside the same `.two-company-field-wrap` as the company-name
     * input, in this order:
     *
     *   input[name='company'] -> query field -> results host -> "not on the list"
     *
     * so the browser's OWN tab order already satisfies §1 ("the query field is
     * the next tab stop after the company-name field"), §2 ("is the next tab
     * stop after the query field") and §4 ("Tab from the not-on-the-list
     * control moves to the next control in the tab order"), with no key
     * handling whatsoever. That is deliberately the opposite of what WC ended
     * up with: select2 appends its dropdown to the end of `<body>`, which is
     * why Tab off its button lands on `<body>` rather than the next form
     * control and has to be re-implemented by hand (see the WC §1 Tab notes on
     * TWO-25326). Anchoring the panel inside the wrapper costs one
     * absolutely-positioned box and buys the whole keyboard contract for free.
     *
     * The panel is `display: none` while closed, so none of it is a tab stop
     * until the buyer opens it - which is also what makes §4's "no keyboard
     * trap anywhere in this control" true by construction rather than by
     * testing.
     *
     * The manual-entry control is a REAL `<button>` and a SIBLING of the
     * results host, never a row inside it (§2): outside the scroll container,
     * so it is reachable without scrolling past up to 50 results, and outside
     * the list, so the cursor keys - which only ever move within the list -
     * cannot reach it.
     */
    buildDropdown() {
        if (!this.companyField || !this.companyField.length) {
            return;
        }
        const wrapper = this.companyField.parent();
        if (!wrapper.length || !wrapper.hasClass('two-company-field-wrap')) {
            return;
        }

        // Adopt an existing panel rather than building a second one. This
        // method runs from setupAutocomplete(), which itself re-runs on every
        // country change and `updatedAddressForm`.
        let panel = wrapper.children('.two-company-dropdown').first();
        if (panel.length) {
            this._dropdown = panel;
            this._queryField = panel.find('.two-company-dropdown__query').first();
            this._resultsList = panel.find('.two-company-dropdown__results').first();
            this._notListedButton = panel.find('.two-company-not-listed').first();
            this._soleTraderButton = panel.find('.two-company-sole-trader-entry').first();
            this._registeredButton = panel.find('.two-company-registered-entry').first();
            // RE-BIND, do not merely adopt the references.
            //
            // Every handler on an existing panel closes over the instance that
            // built it, so adoption has to re-point them at this one. The
            // reachable hazard today is not a stale instance - destroy() takes
            // the panel with it, so a fresh instance always builds rather than
            // adopts - it is DOUBLE binding: this method re-runs on every
            // country change and address-form update, and without the
            // `.off('.twoDropdown')` inside bindDropdownHandlers() each
            // re-entry would stack another copy of the click handler, firing
            // enterManualEntryMode() once per re-entry the buyer happened to
            // trigger. That is pinned by a mutation-verified test.
            //
            // The re-point is kept as well, cheaply, because "the panel is
            // always torn down with its builder" is an invariant of
            // destroy()'s current implementation rather than anything this
            // method can see.
            this.bindDropdownHandlers();
            return;
        }

        panel = $('<div class="two-company-dropdown" hidden></div>');

        const searchRow = $('<div class="two-company-dropdown__search"></div>');
        // `placeholder` carries the LENGTH REQUIREMENT (TWO-40 follow-up), not
        // the watermark wording the company field already showed to get here
        // - a placeholder identical to the field the buyer just clicked past
        // told them nothing new.
        //
        // `aria-label` deliberately does NOT mirror the placeholder (adversarial
        // review finding, round 2). `aria-label` is the field's accessible
        // NAME - set once here and never re-synced - while `placeholder` is a
        // transient hint that visually disappears the moment the field has a
        // value. Naming the field after a hint that stops being true the
        // instant the buyer has typed enough left a screen-reader user
        // tabbing back into the field, after a full query or after picking a
        // result, still hearing "Enter 3 or more characters" as what the
        // field IS, which by then it no longer needs to say and does not
        // describe. `aria-label` instead names the field's role, same
        // pattern WCAG's "Label in Name" expects.
        const query = $('<input type="text" class="two-company-dropdown__query" autocomplete="off" />')
            .attr('placeholder', TwoCompanySearch.getQueryPlaceholderText())
            .attr('aria-label', TwoCompanySearch.getQueryAriaLabelText())
            // Combobox semantics, so the `aria-activedescendant` the fallback
            // engine sets while arrowing through results means something. The
            // jQuery UI path sets its own equivalents on this same input.
            .attr('role', 'combobox')
            .attr('aria-autocomplete', 'list')
            .attr('aria-expanded', 'true');
        // The spinner slot. Painted by CSS from the loading class the widget
        // (or the fallback path) puts on the query field - see
        // `.two-company-dropdown__query.ui-autocomplete-loading` in two.css.
        // A real element rather than a background on the input itself so it
        // sits at the right-hand END of the field (§1) regardless of how the
        // theme has styled the input's own padding.
        searchRow.append(query).append($('<span class="two-company-dropdown__spinner" aria-hidden="true"></span>'));

        // jQuery UI appends its own `<ul class="ui-autocomplete">` into this
        // host. The host is the scroll container; the `<ul>` inside it is
        // flattened into normal flow by CSS. Keeping the scroll on the host
        // rather than on the `<ul>` is what lets the button below sit outside
        // it without restyling a widget-owned element.
        const results = $('<div class="two-company-dropdown__results"></div>');

        // The three-chip mode selector (TWO-40 design revision). A sibling of
        // the search row and the results host, not a row inside the results
        // `<ul>`, for the same reason "My company is not on the list"
        // originally was: reachable without scrolling past up to 50 results,
        // and outside the list so the cursor keys - which only ever move
        // within the list - cannot reach it. Shown immediately whenever the
        // panel is open, with no wait for characters to be typed (unlike
        // Magento's equivalent link, which gates on the 3-character
        // threshold) - see syncModeChipVisibility().
        //
        // "Registered Company" is the default-selected chip and stays
        // visible for as long as the panel is open; clicking it is how the
        // buyer returns to ordinary search from either of the other two
        // (see chip click handlers in bindDropdownHandlers()).
        const registeredEntry = $('<button type="button" class="two-company-mode-chip two-company-registered-entry two-company-mode-chip--selected"></button>')
            .text(this.getRegisteredEntryText());

        // "Enter Manually" replaces the old plain-wording link/button of the
        // same name ("My company is not on the list", TWO-25288/TWO-25326).
        const notListed = $('<button type="button" class="two-company-mode-chip two-company-not-listed"></button>')
            .text(this.getManualEntryText());

        // "Sole Trader" - the sole-trader enrolment entry point (TWO-40).
        // Hidden by default; syncModeChipVisibility() decides whether the
        // registry says the current billing country is eligible, and keeps
        // that current live across a country-selector change.
        const soleTraderEntry = $('<button type="button" class="two-company-mode-chip two-company-sole-trader-entry"></button>')
            .text(this.getSoleTraderEntryText());

        const modeChips = $('<div class="two-company-mode-chips"></div>')
            .append(registeredEntry)
            .append(soleTraderEntry)
            .append(notListed);

        // Positioned AFTER the results host, not before the query field
        // (design nuance flagged for Doug, TWO-40 round 2): keeps the
        // existing "query field is the next tab stop after the company-name
        // field" contract (§1) intact, since the chips are functionally the
        // same slot the old "My company is not on the list" link occupied.
        // If the intent was for the chips to sit ABOVE the query field
        // instead (visually gating which mode the search below is even in),
        // that is a one-line reorder here plus a tab-order re-check.
        panel.append(searchRow).append(results).append(modeChips);
        // After the company field, so DOM order === tab order (see above).
        // `appendTo` the wrapper rather than `.after()` the input: the
        // org-number hint is also a child of this wrapper and the panel must
        // come after it, not between the input and its own hint.
        wrapper.append(panel);

        this._dropdown = panel;
        this._queryField = query;
        this._resultsList = results;
        this._notListedButton = notListed;
        this._soleTraderButton = soleTraderEntry;
        this._registeredButton = registeredEntry;

        this.bindDropdownHandlers();
    }

    /**
     * Remove the panel and unbind everything on it.
     *
     * Scoped to THIS field's own wrapper rather than a document-wide class
     * sweep: the panel carries focus-moving controls, so a global remove could
     * delete a second, independently-constructed instance's still-live panel
     * out from under it. The passive org-number hint and the return-to-search
     * link can afford a document-wide sweep; a focus-moving control cannot.
     */
    removeDropdown() {
        // Same reasoning as closeDropdown()'s own call to this - a full
        // teardown must not leave the settle listener bound past the panel
        // it would have called closeDropdown() on (TWO-40 round 4).
        this.endSoleTraderLoading();
        clearTimeout(this._closeTimerId);
        this._closeTimerId = null;
        this._dropdownOpen = false;
        this._pointerInPanel = false;
        // Before the container reference below is dropped, or the pending
        // release fires against a panel that no longer exists.
        this.releaseResultsHeight();
        $(document).off('mouseup.twoDropdown' + this._instanceNs);
        $(window).off('blur.twoDropdown' + this._instanceNs);
        // Release the jQuery UI widget FIRST, while its element is still
        // attached. `_create` binds handlers on `document` that removing the
        // element does not unbind, so a panel dropped without this leaks one
        // set of document-level handlers per address-form re-render - and
        // PrestaShop fires that event for something as ordinary as a country
        // change. Before TWO-25326 the widget lived on the company-name field
        // and setupAutocomplete()'s own node-swap branch released it there;
        // that call now guards a node which can never hold a widget, so the
        // release has to happen here, where the query field is known.
        if (this._queryField && this._queryField.length) {
            try {
                if (this._queryField.hasClass('ui-autocomplete-input')) {
                    this._queryField.autocomplete('destroy');
                }
            } catch (e) {
                // Foreign or half-initialised widget; the node goes either way.
            }
        }
        if (this._notListedButton && this._notListedButton.length) {
            this._notListedButton.off('.twoDropdown');
        }
        if (this._soleTraderButton && this._soleTraderButton.length) {
            this._soleTraderButton.off('.twoDropdown');
        }
        if (this._registeredButton && this._registeredButton.length) {
            this._registeredButton.off('.twoDropdown');
        }
        if (this._dropdown && this._dropdown.length) {
            // Native listener, so jQuery's namespace sweep above does not
            // reach it - it has to come off by reference.
            if (this._tabCaptureHandler && this._dropdown.get(0)) {
                this._dropdown.get(0).removeEventListener('keydown', this._tabCaptureHandler, true);
            }
            this._tabCaptureHandler = null;
            this._dropdown.off('.twoDropdown');
            this._dropdown.remove();
        }
        if (this.companyField && this.companyField.length) {
            this.companyField.parent().children('.two-company-dropdown').off('.twoDropdown').remove();
        }
        this._dropdown = null;
        this._queryField = null;
        this._resultsList = null;
        this._notListedButton = null;
        this._soleTraderButton = null;
        this._registeredButton = null;
    }

    /**
     * Bind every handler the panel needs, to THIS instance.
     *
     * Called on build AND on adoption, and it unbinds the `.twoDropdown`
     * namespace first so it is idempotent - see the adoption branch in
     * buildDropdown() for why re-binding rather than adopting matters.
     *
     * Covers: activation of "My company is not on the list", Escape-to-close,
     * and close-on-focus-leaving-the-panel (§1).
     *
     * Escape is bound to the panel rather than to the document: a document
     * handler would swallow Escape for every other control on the checkout,
     * which is precisely the "key events must only ever be tied to individual
     * controls" rule in §4.
     *
     * The close-on-leave is a deferred `focusout`, not a `blur`. Focus moving
     * from the query field to the "not on the list" button is two events - a
     * `focusout` on the first and a `focusin` on the second - in that order,
     * so an immediate close would tear the panel down mid-Tab and drop the
     * buyer on `<body>`. Deferring one tick and cancelling on any `focusin`
     * within the panel makes "left the panel" mean what it says.
     */
    bindDropdownHandlers() {
        if (!this._dropdown || !this._dropdown.length) {
            return;
        }
        this._dropdown.off('.twoDropdown');
        if (this._notListedButton && this._notListedButton.length) {
            this._notListedButton.off('.twoDropdown');
            this._notListedButton.on('click.twoDropdown', (event) => {
                event.preventDefault();
                // Same reasoning as renderBackToSearchLink()'s own
                // stopPropagation (#30.x.14 bug 2.5, live-verified): this
                // button sits inside the address step's markup and the theme
                // binds a delegated accordion-toggle handler above it, which
                // reads a stray click as "collapse this step".
                event.stopPropagation();
                this._chipMode = 'manual';
                // Abandons any Sole Trader wait in progress (TWO-40 round 4)
                // - this handler does not go through closeDropdown(), so
                // without this the query-field spinner would keep spinning
                // and the settle listener would stay bound past the flow the
                // buyer just walked away from.
                this.endSoleTraderLoading();
                this.enterManualEntryMode();
            });
        }

        // "Sole Trader" (TWO-40) - same click-handling shape as "Enter
        // Manually" above, including the stopPropagation for the same
        // accordion-toggle reason. Enrolment is owned entirely by
        // TwoSoleTrader.js; this control only decides when to offer the
        // entry point and hands off to it.
        if (this._soleTraderButton && this._soleTraderButton.length) {
            this._soleTraderButton.off('.twoDropdown');
            this._soleTraderButton.on('click.twoDropdown', (event) => {
                event.preventDefault();
                event.stopPropagation();
                // Re-entrancy guard (TWO-40 round 5, adversarial review
                // finding - Han/Vader both independently caught this): round
                // 4 keeps this button clickable for the WHOLE round trip
                // rather than closing on the first click, which newly makes
                // a second click reachable while the first is still waiting.
                // TwoSoleTrader.js's own guards only cover the token-mint
                // stage (`isFetchingTokens`); a second click landing during
                // the buyer-lookup stage re-entered startEnrollment()'s
                // "resume" branch and fired a second concurrent
                // getCurrentBuyer() - on the no-match path that opened TWO
                // signup popups from one buyer gesture. `_soleTraderLoading`
                // is exactly "a flight for this click is already in
                // progress", so bail out rather than starting a second one.
                if (this._soleTraderLoading) {
                    return;
                }
                this._chipMode = 'sole_trader';
                // Unlike "Registered Company" (below), this chip's own click
                // handler is the only place `sole_trader` mode is entered, so
                // it must render its own selection state rather than relying
                // on a caller to. Must run BEFORE closeDropdown(true): the
                // panel only hides via CSS (`display:none`/`hidden`), it
                // never detaches the chip buttons, so the class write is not
                // lost by closing - but doing it before keeps this handler's
                // ordering symmetric with the other two.
                this.renderChipSelection();
                // Re-clicking this chip while a sole trader is already
                // adopted (TWO-40 follow-up, Doug's explicit ruling: an
                // earlier round made this a no-op, treating the standalone
                // "select a different sole trader" link/button as the only
                // entry point - that was wrong). Route through the exact
                // same call the link uses rather than starting a fresh
                // enrolment, which would re-mint tokens for an identity
                // that's already adopted.
                if (this.isSoleTraderAdopted()) {
                    this.triggerSelectDifferentSoleTrader();
                    return;
                }
                if (window.TwoSoleTrader_Instance
                    && typeof window.TwoSoleTrader_Instance.startEnrollment === 'function') {
                    // Keep the panel OPEN and show the query field's own
                    // spinner for the actual duration of this click's
                    // autofill round trip (Doug, TWO-40 round 4), instead of
                    // closing immediately. This also subsumes the round-3
                    // paint-timing fix that used to defer closeDropdown() by
                    // one requestAnimationFrame: the panel now stays open for
                    // the whole flight - far longer than one frame - so the
                    // `--selected` state a buyer actually sees is no longer a
                    // timing accident, it is the real in-progress state.
                    // beginSoleTraderLoading()/endSoleTraderLoading() do the
                    // work; see their own comments for the event contract
                    // with TwoSoleTrader.js.
                    this.beginSoleTraderLoading();
                    try {
                        // TWO-40 round 5 (Vader finding): startEnrollment() is
                        // foreign-module code called with no try/catch
                        // before this - a synchronous throw would leave the
                        // spinner open with nothing left to ever settle it.
                        window.TwoSoleTrader_Instance.startEnrollment();
                    } catch (e) {
                        this.endSoleTraderLoading();
                        this.closeDropdown(true);
                    }
                } else {
                    // Nothing to wait on - close the same way "Registered
                    // Company"/"Enter Manually" do. Deferred by one
                    // requestAnimationFrame (the round-3 paint-timing fix,
                    // reinstated here TWO-40 round 5 - adversarial review
                    // finding): this branch does not go through
                    // beginSoleTraderLoading()'s keep-open window at all, so
                    // without this it is the exact same "renderChipSelection()
                    // and closeDropdown() in the same synchronous tick, zero
                    // painted frames" bug the rest of this handler exists to
                    // fix. Only reachable when the global instance is
                    // missing/malformed.
                    window.requestAnimationFrame(() => this.closeDropdown(true));
                }
            });
        }

        // "Registered Company" (TWO-40) - the default chip and the way BACK
        // to ordinary search from either of the other two, without closing
        // the panel: unlike "Enter Manually"/"Sole Trader", picking this one
        // is not a hand-off to a different flow, it is "stay here, search
        // normally". Reverses whichever of the other two modes was active -
        // both reversals are no-ops if that mode was never entered.
        if (this._registeredButton && this._registeredButton.length) {
            this._registeredButton.off('.twoDropdown');
            this._registeredButton.on('click.twoDropdown', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this._chipMode = 'registered';
                if (this._manualEntry) {
                    this.exitManualEntryMode();
                }
                // BEFORE cancelEnrollment() (TWO-40 round 5, adversarial
                // review finding), not after - see the reasoning at
                // notifyEnrollmentSettled() in TwoSoleTrader.js:
                // cancelEnrollment() now fires the SAME settle event that
                // beginSoleTraderLoading()'s own listener reacts to by
                // calling closeDropdown(true). This handler wants to STAY
                // OPEN, not close - so its own listener must already be
                // unbound before cancelEnrollment() can dispatch, or this
                // click would immediately close the very panel it is trying
                // to keep open.
                this.endSoleTraderLoading();
                if (window.TwoSoleTrader_Instance
                    && typeof window.TwoSoleTrader_Instance.cancelEnrollment === 'function') {
                    window.TwoSoleTrader_Instance.cancelEnrollment();
                }
                this.renderChipSelection();
                if (this._queryField && this._queryField.length) {
                    this._queryField.trigger('focus');
                }
            });
        }

        this._dropdown.on('keydown.twoDropdown', (event) => {
            // Enter inside an OPEN panel never reaches the address form.
            //
            // This handler is on the panel, so it runs after the widget's own
            // input handler has had the keystroke - jQuery UI only
            // `preventDefault`s Enter when it has an active menu item, and the
            // fallback engine only when it has an active row. In every other
            // state (too short to search, "No matches found", results painted
            // but nothing highlighted) Enter fell through to PrestaShop's
            // `<form>` and triggered implicit submission: the buyer types a
            // company name, presses Enter before the results land, and submits
            // the address step.
            //
            // Scoped to the QUERY FIELD, not the whole panel. A `<button>`'s
            // activation click IS the default action of the Enter keydown that
            // triggered it, so cancelling Enter in a bubbling ancestor
            // suppresses the click outright - which silently broke Enter on
            // "My company is not on the list" (§2: click, Enter and Space must
            // all activate it). Space was unaffected only by accident, because
            // a button's Space activation fires on keyup, which this never
            // saw. The form-submission this guard exists to stop can only come
            // from the query field anyway.
            if (event.key === 'Enter'
                && this._queryField && this._queryField.length
                && event.target === this._queryField.get(0)) {
                event.preventDefault();
            }
            if (event.key === 'Escape' || event.key === 'Esc') {
                event.preventDefault();
                event.stopPropagation();
                // §1: Escape reverts focus to the company-name field.
                this.closeDropdown(true);
            }
        });

        // Tab out of the query field must NOT pick the highlighted row (§4.1).
        //
        // jQuery UI's autocomplete treats Tab as "accept the active menu item"
        // - its own keydown handler calls `menu.select(event)` whenever a row
        // has been arrow-keyed onto. That runs our `select` option, which ends
        // in closeDropdown(true) and puts focus back on the company-name
        // field: precisely the two things §1.9 and §4.1 forbid Tab from doing.
        // A buyer who arrows down to look at a result and then tabs would find
        // it silently chosen for them.
        //
        // Native listener in the CAPTURE phase, deliberately. The widget binds
        // its handler on the query input itself, and a jQuery handler added
        // afterwards on the same element runs after it - too late. Capture on
        // an ancestor runs before the target's own listeners, so
        // stopPropagation() here means the widget never sees the keystroke.
        //
        // Only propagation is stopped, never the default: letting the browser
        // perform its own Tab is what makes the next stop correct in both
        // directions without this code choosing a destination. Forward, the
        // next tabbable inside the panel is the "not on the list" button (the
        // results list and the widget's `<ul>` both carry `tabindex="-1"`),
        // which is the §4.1 shortcut; backward, focus leaves the panel and the
        // focusout handler closes it.
        const panelEl = this._dropdown.get(0);
        if (panelEl) {
            if (this._tabCaptureHandler) {
                panelEl.removeEventListener('keydown', this._tabCaptureHandler, true);
            }
            this._tabCaptureHandler = (event) => {
                if (event.key !== 'Tab') {
                    return;
                }
                if (!this._queryField || !this._queryField.length
                    || event.target !== this._queryField.get(0)) {
                    return;
                }
                event.stopPropagation();
            };
            panelEl.addEventListener('keydown', this._tabCaptureHandler, true);
        }

        this._dropdown.on('focusin.twoDropdown', () => {
            clearTimeout(this._closeTimerId);
            this._closeTimerId = null;
        });

        this._dropdown.on('focusout.twoDropdown', () => {
            this.scheduleDropdownClose();
        });

        // Dragging the results scrollbar moves focus to `<body>` in Chrome -
        // no element inside the panel is focused, so the focusout close above
        // fires and the panel disappears mid-scroll. With up to 50 results
        // that is the ordinary way to browse them. A pointer held down
        // anywhere on the panel, scrollbar included, means the buyer is still
        // using it; the close is re-evaluated when they let go.
        this._dropdown.on('mousedown.twoDropdown', () => {
            this._pointerInPanel = true;
            this.freezeResultsHeight();
        });
        $(document).off('mouseup.twoDropdown' + this._instanceNs)
            .on('mouseup.twoDropdown' + this._instanceNs, () => {
                if (!this._pointerInPanel) {
                    return;
                }
                this._pointerInPanel = false;
                // Release AFTER the browser has finished turning this
                // mousedown/mouseup pair into a `click` - see
                // freezeResultsHeight() for why the height was pinned at all.
                this.releaseResultsHeightSoon();
                if (!this._dropdownOpen || this._destroyed) {
                    return;
                }
                // Do NOT schedule a close here. That was the first attempt at
                // this fix and it only moved the timing: after a scrollbar
                // drag `document.activeElement` IS `<body>` - that is the
                // premise of the bug - so the deferred close's
                // "focus is outside the panel" test passes and the panel
                // still disappears, just on mouseup instead of mousedown.
                //
                // The buyer dragged the panel's own scrollbar, which is a
                // statement that they are still using it. Put focus back where
                // it was before the drag stole it, and the panel simply stays
                // open because nothing has left it.
                const active = document.activeElement;
                const panelEl = this._dropdown && this._dropdown.length ? this._dropdown.get(0) : null;
                if (panelEl && active && panelEl.contains(active)) {
                    return;
                }
                if (this._queryField && this._queryField.length) {
                    this._queryField.trigger('focus');
                }
            });

        // A drag begun on the panel and released OUTSIDE the window fires no
        // `mouseup` this document ever sees, so the flag above would stay
        // `true` and suppress every subsequent focus-out close for the rest of
        // the panel's life - the panel stays on screen with focus long gone.
        // Losing the window is proof the pointer is no longer interacting with
        // the panel, whatever happened to the button release.
        $(window).off('blur.twoDropdown' + this._instanceNs)
            .on('blur.twoDropdown' + this._instanceNs, () => {
                this._pointerInPanel = false;
                this.releaseResultsHeight();
            });
    }

    /**
     * Pin the results area's height for the duration of a pointer press.
     *
     * A `<button>` is only activated when the mousedown and the mouseup land
     * on the SAME element - otherwise the browser dispatches `click` on the
     * nearest common ancestor of the two, and the button's own handler never
     * runs. The results area sits directly above "My company is not on the
     * list", so anything that changes its height mid-press slides the button
     * out from under the pointer and silently swallows the activation.
     *
     * That is exactly what happened, and it is why manual entry was
     * unreachable by mouse. Pressing the button moves focus off the query
     * field; the blur empties the results area (jQuery UI closes its menu on
     * blur, and results/messages live in that same container); the button
     * jumps up by the height of whatever was showing; mouseup lands on the
     * form behind the panel. Measured in real Chromium: results 30px -> 0px
     * between mousedown and mouseup, button top 658 -> 627, `click` retargeted
     * from the button to `<section class="form-fields">`.
     *
     * jsdom cannot see this - it has no layout, every rect is 0x0, and it
     * dispatches `click` wherever it is told regardless of what moved. So the
     * unit suite passed throughout while a real buyer could not reach manual
     * entry at all. The regression test for this asserts the mechanism (the
     * height is pinned for the press and released after) rather than the
     * geometry, which is the most jsdom can honestly prove.
     *
     * Pinning rather than suppressing the emptying: the emptying itself is
     * correct and comes from the widget, and the invariant that actually
     * matters is narrower and engine-independent - nothing above the button
     * may reflow between press and release. This also covers the fallback
     * search engine, which renders that container itself.
     */
    freezeResultsHeight() {
        if (this._destroyed || !this._resultsList || !this._resultsList.length) {
            return;
        }
        const el = this._resultsList.get(0);
        if (!el) {
            return;
        }
        // Re-entrant presses must not re-pin to a height this method itself
        // established, or a stale value outlives the gesture that set it.
        if (this._resultsHeightFrozen) {
            return;
        }
        clearTimeout(this._resultsFreezeReleaseId);
        this._resultsFreezeReleaseId = null;
        this._resultsHeightFrozen = true;
        this._resultsList.css('min-height', el.getBoundingClientRect().height + 'px');
    }

    /**
     * Drop the pinned height, letting the results area size to its content
     * again. Safe to call when nothing is pinned.
     */
    releaseResultsHeight() {
        clearTimeout(this._resultsFreezeReleaseId);
        this._resultsFreezeReleaseId = null;
        if (!this._resultsHeightFrozen) {
            return;
        }
        this._resultsHeightFrozen = false;
        if (this._resultsList && this._resultsList.length) {
            this._resultsList.css('min-height', '');
        }
    }

    /**
     * Release on the next tick rather than immediately.
     *
     * This runs from a `mouseup` handler, and the `click` the browser
     * synthesises from that press has not been dispatched yet. Un-pinning here
     * and now would let the panel reflow before the button's own handler runs,
     * which is the very failure this pins against - just moved one event
     * later.
     */
    releaseResultsHeightSoon() {
        clearTimeout(this._resultsFreezeReleaseId);
        this._resultsFreezeReleaseId = setTimeout(() => {
            this._resultsFreezeReleaseId = null;
            this.releaseResultsHeight();
        }, 0);
    }

    /**
     * Close once focus has genuinely settled somewhere outside the panel.
     *
     * Deliberately does NOT move focus. This fires on the way OUT - a Tab off
     * the "not on the list" button, or a click elsewhere on the form - and the
     * browser has already chosen the destination. Pulling focus back to the
     * company-name field here is exactly the keyboard trap §4 forbids.
     */
    scheduleDropdownClose() {
        clearTimeout(this._closeTimerId);
        this._closeTimerId = setTimeout(() => {
            this._closeTimerId = null;
            if (this._destroyed || !this._dropdown || !this._dropdown.length) {
                return;
            }
            // Still being pointed at (a scrollbar drag) - not abandoned.
            if (this._pointerInPanel) {
                return;
            }
            const active = document.activeElement;
            if (active && this._dropdown.get(0).contains(active)) {
                return;
            }
            this.closeDropdown(false);
        }, 0);
    }

    /**
     * Open the panel and put focus in the query field (§1).
     *
     * The company-name field is NOT touched - not its value, not its
     * selection. That is the entire point of the panel: §1 requires the
     * company-name field to be left unchanged until the buyer picks a result.
     *
     * The query field starts EMPTY rather than seeded from the company-name
     * field. Seeding it would re-run a search for a company the buyer has
     * already confirmed, and the first thing they would see on reopening is a
     * list containing only the company they are trying to move away from.
     */
    openDropdown() {
        if (this._destroyed || this._manualEntry) {
            return;
        }
        if (!this._dropdown || !this._dropdown.length
            || !this._queryField || !this._queryField.length) {
            return;
        }
        // BEFORE cancelEnrollment() (TWO-40 round 5, adversarial review
        // finding - same reasoning as the "Registered Company" handler):
        // cancelEnrollment() now fires the settle event too, and this
        // method's own listener reacting to it would call closeDropdown(true)
        // from INSIDE openDropdown() itself, re-closing the very panel this
        // call is in the middle of opening. Unbind first.
        this.endSoleTraderLoading();
        // Reopening the search control is the buyer choosing ordinary company
        // search over an "I'm a sole trader" row they may have clicked
        // moments earlier (TWO-40). Cancel any not-yet-completed enrolment -
        // TwoSoleTrader.js keeps its minted tokens either way, so a buyer who
        // comes back to this row resumes rather than re-mints.
        if (window.TwoSoleTrader_Instance
            && typeof window.TwoSoleTrader_Instance.cancelEnrollment === 'function') {
            window.TwoSoleTrader_Instance.cancelEnrollment();
        }
        clearTimeout(this._closeTimerId);
        this._closeTimerId = null;
        // Cleared on every open: the flag is otherwise only reset by a
        // matching `mouseup`, and a right-click on the panel or a drag
        // released outside the window never produces one - leaving the
        // focus-out close suppressed for the rest of the instance's life.
        this._pointerInPanel = false;

        this._dropdownOpen = true;
        this._dropdown.removeAttr('hidden').show();
        this.setDropdownExpandedState();
        // Every FRESH open starts at the default chip (TWO-40: "Default
        // selected chip: Registered Company") - UNLESS a sole trader is
        // currently adopted (TWO-40 follow-up, Doug live-test finding). The
        // earlier version of this comment reasoned that cancelEnrollment()
        // above already reverses the only other source of 'sole_trader',
        // which is true for an in-flight ENROLMENT but not for an already-
        // ADOPTED identity: cancelEnrollment() only cancels a signup in
        // progress, it does not un-adopt a completed one. A sole trader IS
        // what's currently selected in that state, so the reopened panel
        // must show that chip selected, not silently fall back to
        // "Registered Company".
        this._chipMode = this.isSoleTraderAdopted() ? 'sole_trader' : 'registered';
        this.renderChipSelection();
        this.syncNotListedVisibility();
        this.syncSoleTraderEntryVisibility();
        this.syncRegisteredEntryVisibility();
        this.focusPanelEntry();
        // Render the current state immediately - for an empty query that is
        // the "type N more characters" hint (§1), not an empty or absent
        // panel. Matches the requirement that the hint is visible as soon as
        // the control opens, which is the Hyvä failure recorded on this
        // ticket.
        this.openSearchForCurrentTerm();
    }

    /**
     * Where focus goes when the panel opens.
     *
     * The query field, normally. But in sole-trader mode that field is not
     * rendered at all (syncQueryFieldSuppression()), and `.focus()` on a
     * `display:none` element does nothing - which would leave focus on the
     * company-name field, OUTSIDE the panel, where neither the
     * Escape-to-close nor the close-on-focus-leave handler can see a
     * keystroke: a keyboard buyer would have no way to close the panel they
     * just opened. So focus the selected chip instead - inside the panel, and
     * the only control this state offers. The Registered company chip is the
     * fallback for the one state where the Sole trader chip is itself hidden
     * (adopted, then the registry stops offering that country).
     */
    focusPanelEntry() {
        if (this._chipMode === 'sole_trader') {
            const chips = [this._soleTraderButton, this._registeredButton];
            for (let i = 0; i < chips.length; i++) {
                const chip = chips[i];
                if (chip && chip.length && chip.css('display') !== 'none') {
                    chip.trigger('focus');
                    return;
                }
            }
        }
        if (this._queryField && this._queryField.length) {
            this._queryField.trigger('focus');
        }
    }

    /**
     * Close the panel.
     *
     * @param {boolean} returnFocus Put focus back on the company-name field.
     *   True for Escape and for a completed selection (§1); false when the
     *   browser has already moved focus somewhere else of its own accord.
     */
    closeDropdown(returnFocus) {
        // Every way the panel closes must leave no sole-trader spinner or
        // stray settle-listener behind, not only the settle event's own
        // path back into here (TWO-40 round 4) - Escape, for instance, goes
        // straight to closeDropdown() without going through
        // endSoleTraderLoading() otherwise.
        this.endSoleTraderLoading();
        clearTimeout(this._closeTimerId);
        this._closeTimerId = null;
        this._dropdownOpen = false;
        // A closed panel must stay closed. The re-render path re-arms this
        // immediately after calling here, which is the one case where a
        // rebuild is allowed to reopen; every other close - Escape, a
        // selection, focus leaving the panel, entering manual entry - is the
        // buyer's own and outranks a deadline an earlier re-render set.
        TwoCompanySearch._reopenPanelUntil = 0;
        // State hygiene, and DEFENSIVE ONLY - deliberately not covered by a
        // test, because no test can currently make it matter. The flag is only
        // ever read by scheduleDropdownClose(), which runs solely while the
        // panel is open, and every route back to open goes through
        // openDropdown(), which already clears it. So a stale `true` surviving
        // a close cannot be observed today. It is reset anyway because that
        // reachability argument is a property of the current call graph rather
        // than of this method, and "closed" plainly means nothing is pointing
        // into the panel. The genuinely reachable stranding path - a drag
        // released outside the window, which fires no `mouseup` at all - is
        // handled by the `window blur` handler in bindDropdownHandlers(), and
        // that one IS pinned by a test.
        this._pointerInPanel = false;
        this.setDropdownExpandedState();
        if (this._dropdown && this._dropdown.length) {
            this._dropdown.hide().attr('hidden', 'hidden');
        }
        if (this._queryField && this._queryField.length) {
            this._queryField.val('');
            this._queryField.removeClass('ui-autocomplete-loading two-company-search-loading');
            try {
                if (this._queryField.hasClass('ui-autocomplete-input')) {
                    this._queryField.autocomplete('close');
                }
            } catch (e) {
                // Widget absent or already released; the panel is hidden either way.
            }
        }
        if (returnFocus && this.companyField && this.companyField.length
            && document.contains(this.companyField.get(0))) {
            this.companyField.trigger('focus');
        }
    }

    /**
     * Keep `aria-expanded` on the company-name field honest.
     *
     * Set on every open and close, not once at setup: the attribute is what
     * tells a screen-reader user whether the popup this control advertises
     * (`aria-haspopup="listbox"`) is currently showing, and a value written
     * once is wrong from the first interaction onward.
     *
     * Only while the field is actually acting as the trigger - in manual-entry
     * mode it is a plain text input and carries neither attribute.
     */
    setDropdownExpandedState() {
        if (!this.companyField || !this.companyField.length || this._manualEntry) {
            return;
        }
        if (this.companyField.attr('aria-haspopup')) {
            this.companyField.attr('aria-expanded', this._dropdownOpen ? 'true' : 'false');
        }
    }

    /**
     * Visibility gating for the "Enter Manually" mode chip (TWO-40 design
     * revision of §2's "My company is not on the list").
     *
     * ALWAYS visible whenever the panel is open - Doug's spec for the
     * three-chip picker is explicit that this one and "Registered Company"
     * are always in the set, unlike "Sole Trader" which is conditional. No
     * gating on a confirmed selection or on characters typed: the buyer must
     * have a route into manual entry (or back out of a selection into it)
     * without typing a doomed query first, which is the WC regression
     * originally recorded on TWO-25326 for this same affordance under its
     * previous, narrower gating.
     */
    syncNotListedVisibility() {
        if (!this._notListedButton || !this._notListedButton.length) {
            return;
        }
        if (this._dropdownOpen) {
            this._notListedButton.show();
        } else {
            this._notListedButton.hide();
        }
    }

    /**
     * Visibility gating for the "Registered Company" mode chip (TWO-40).
     * Always visible whenever the panel is open, same as "Enter Manually" -
     * it is the default, not a conditional option.
     */
    syncRegisteredEntryVisibility() {
        if (!this._registeredButton || !this._registeredButton.length) {
            return;
        }
        if (this._dropdownOpen) {
            this._registeredButton.show();
        } else {
            this._registeredButton.hide();
        }
    }

    /**
     * Visibility gating for the "Sole Trader" mode chip (TWO-40).
     *
     * The ONE conditional chip of the three: open AND-ed with the registry's
     * own per-billing-country answer, read from TwoSoleTrader.js's
     * availability cache rather than duplicated here. `TwoSoleTrader_Instance`
     * may not exist yet (script load order) or may not have resolved an
     * answer for the current country yet; both read as "not available",
     * matching this control's fail-soft posture everywhere else. Reactivity
     * to a live country-selector change is inherited rather than built here:
     * setupCountryChangeListener() already closes the panel on every country
     * change, so the next open always re-evaluates this against the current
     * country - the chip cannot go stale while sitting open across a change.
     */
    syncSoleTraderEntryVisibility() {
        if (!this._soleTraderButton || !this._soleTraderButton.length) {
            return;
        }
        const instance = window.TwoSoleTrader_Instance;
        const available = !!(instance && typeof instance.isAvailableForCurrentCountry === 'function'
            && instance.isAvailableForCurrentCountry());
        const show = available && this._dropdownOpen;
        if (show) {
            this._soleTraderButton.show();
        } else {
            this._soleTraderButton.hide();
        }
    }

    /**
     * Reflect `this._chipMode` onto the `--selected` class of all three mode
     * chips (TWO-40). Purely cosmetic bookkeeping - the actual mode-switching
     * behaviour lives in each chip's own click handler and in
     * enterManualEntryMode()/exitManualEntryMode().
     */
    renderChipSelection() {
        const chips = [
            [this._soleTraderButton, 'sole_trader'],
            [this._registeredButton, 'registered'],
            [this._notListedButton, 'manual']
        ];
        chips.forEach(([button, mode]) => {
            if (button && button.length) {
                button.toggleClass('two-company-mode-chip--selected', this._chipMode === mode);
            }
        });
        this.syncQueryFieldSuppression();
    }

    /**
     * Suppress the free-text query input while the Sole Trader chip is the
     * selected one (TWO-40 follow-up, Doug live-test finding). There is
     * deliberately only one way to pick a different company while a sole
     * trader is selected - the chip/link re-launching the signup flow (see
     * triggerSelectDifferentSoleTrader()) - typing a fresh live-search query
     * is not it. Called from renderChipSelection() so every place the chip
     * selection changes - a fresh open, either chip's click handler - stays
     * in sync with no separate call site to remember.
     *
     * HIDDEN, not merely `readonly` (Doug live-test finding, TWO-40 follow-up
     * round 2): an earlier round made the field readonly and left it on
     * screen, which reads as a search box that has stopped working. A field
     * offering nothing must not be painted. `display:none` (plus the `hidden`
     * attribute, same belt-and-braces as the panel itself) rather than
     * `visibility`/`opacity`, so it leaves the tab order with it - a
     * keyboard-only buyer must not land on an input they cannot see.
     *
     * The whole SEARCH ROW is hidden, not just the input: the spinner is an
     * absolutely-positioned sibling inside that row, so hiding the input
     * alone collapses the row to zero height and strands the spinner at its
     * top edge. Which is also why the hide stands down while a sole-trader
     * flight is in progress - that spinner, in this field, IS the in-flight
     * state (see beginSoleTraderLoading()).
     *
     * The term is dropped on the way out. The row comes back on the
     * "Registered company" chip, and a query the buyer typed before adopting
     * describes a company they then did not pick; restoring it would put a
     * stale term above results that no longer match it.
     */
    syncQueryFieldSuppression() {
        if (!this._queryField || !this._queryField.length) {
            return;
        }
        const suppressed = this._chipMode === 'sole_trader';
        this._queryField.prop('readonly', suppressed);
        const searchRow = this._queryField.closest('.two-company-dropdown__search');
        if (!searchRow.length) {
            return;
        }
        if (suppressed && !this._soleTraderLoading) {
            this._queryField.val('');
            searchRow.hide().attr('hidden', 'hidden');
        } else {
            searchRow.removeAttr('hidden').show();
        }
    }

    /**
     * Whether a sole-trader identity is currently adopted into the form
     * (TWO-40 follow-up). The "Select a different sole trader" link/button
     * only ever exists for exactly this state (see
     * renderSelectDifferentSoleTraderLink()/removeSelectDifferentSoleTraderLink()),
     * so its presence is the single source of truth rather than a second,
     * independently-maintained flag that could drift from it.
     *
     * @returns {boolean}
     */
    isSoleTraderAdopted() {
        return !!(this._selectDifferentSoleTraderLink && this._selectDifferentSoleTraderLink.length);
    }

    /**
     * Keep the panel open and show the query field's own spinner for the
     * duration of a Sole Trader click's real autofill round trip (TWO-40
     * round 4, Doug's explicit request: "keep the company search control
     * open, show spinner in query field"). Reuses the SAME spinner the
     * ordinary search path already shows - `.two-company-dropdown__spinner`,
     * toggled by the `two-company-search-loading` class on the query field
     * (see two.css) - rather than inventing a second one; §1 already settled
     * where an in-field spinner on this control lives.
     *
     * Bound to a single, namespaced `document` listener for
     * `two:sole-trader-flight-settled` - the event TwoSoleTrader.js's
     * notifyEnrollmentSettled() fires from every terminal branch of
     * startEnrollment()'s call graph (success, failure, or a hand-off to the
     * on-page prompt/popup). One-shot in effect: endSoleTraderLoading()
     * unbinds it as its first act, so a second, unrelated settle (there
     * should not be one for a single click, but nothing here depends on
     * that) is a no-op rather than a second close.
     */
    beginSoleTraderLoading() {
        if (this._soleTraderLoading) {
            return;
        }
        this._soleTraderLoading = true;
        if (this._queryField && this._queryField.length) {
            this._queryField.addClass('two-company-search-loading');
        }
        // The chip is already `sole_trader` by the time this runs, so the
        // search row has just been hidden by syncQueryFieldSuppression() -
        // re-show it for the flight, or the spinner this method exists to
        // paint has nowhere to appear.
        this.syncQueryFieldSuppression();
        $(document).off('two:sole-trader-flight-settled.twoSoleTraderFlight' + this._instanceNs)
            .on('two:sole-trader-flight-settled.twoSoleTraderFlight' + this._instanceNs, () => {
                // closeDropdown() itself calls endSoleTraderLoading() as its
                // own first line (TWO-40 round 5 cleanup, Yoda finding) - no
                // need to call it again here too, and this keeps every close
                // path funnelled through the one centralized call.
                this.closeDropdown(true);
            });
    }

    /**
     * Reverse beginSoleTraderLoading(): drop the spinner and the listener.
     * Called from closeDropdown() itself (so EVERY way the panel closes -
     * the settle event routes through there too now - Escape, reopening,
     * "Registered Company"/"Enter Manually", or anything else leaves no
     * listener or spinner behind), and also called directly (not via a
     * close) from those same chip handlers and openDropdown(), which is why
     * this stays idempotent rather than assuming it is only ever called
     * once.
     */
    endSoleTraderLoading() {
        if (!this._soleTraderLoading) {
            return;
        }
        this._soleTraderLoading = false;
        $(document).off('two:sole-trader-flight-settled.twoSoleTraderFlight' + this._instanceNs);
        if (this._queryField && this._queryField.length) {
            this._queryField.removeClass('two-company-search-loading');
        }
        // Mirror of the call in beginSoleTraderLoading(): the keep-open
        // window is over, so a still-selected Sole Trader chip goes back to
        // hiding the row. A no-op for every other mode.
        this.syncQueryFieldSuppression();
    }

    /**
     * Make the company-name field a search TRIGGER rather than a text box
     * while search mode is active (§1).
     *
     * `readonly`, deliberately, and not `disabled`: a readonly input still
     * submits its value, still takes focus, and is still a tab stop - all
     * three of which this field needs, because it IS PrestaShop's own address
     * field and its value is the company name that gets saved. What readonly
     * removes is the one thing §1 forbids: the buyer typing into it and
     * silently overwriting a confirmed name outside of a real selection.
     *
     * Removed again in manual-entry mode, where this field is the thing the
     * buyer is supposed to type into.
     */
    setCompanyFieldSearchMode(searchMode) {
        if (!this.companyField || !this.companyField.length) {
            return;
        }
        if (searchMode) {
            this.companyField.attr('readonly', 'readonly');
            this.companyField.attr('aria-haspopup', 'listbox');
            this.companyField.attr('aria-expanded', this._dropdownOpen ? 'true' : 'false');
            this.companyField.attr('title', this.getEditCompanyText());
        } else {
            this.companyField.removeAttr('readonly');
            this.companyField.removeAttr('aria-haspopup');
            this.companyField.removeAttr('aria-expanded');
            this.companyField.removeAttr('title');
        }
        this.syncCompanyFieldPlaceholder(searchMode);
    }

    /**
     * Keep the placeholder describing the mode the field is actually in.
     *
     * "Enter company name to search" is a search-mode instruction. In
     * manual-entry mode the field no longer searches anything - it is the
     * plain text input the buyer types their company into - so that wording
     * tells them to do something the field will not do. Found on the staging
     * shop in a real browser while verifying the manual-entry route.
     *
     * Only ever swaps a placeholder THIS class put there. applyEmptyFieldHint()
     * declines to touch a placeholder a merchant theme or an address-form
     * override already set, and undoing that here would take with one hand what
     * that rule gives with the other - so a theme's own wording is left in both
     * modes.
     *
     * @param {boolean} searchMode
     */
    syncCompanyFieldPlaceholder(searchMode) {
        if (!this.companyField || !this.companyField.length) {
            return;
        }
        const searchText = TwoCompanySearch.getEmptyFieldHintText();
        const manualText = TwoCompanySearch.getManualEntryPlaceholderText();
        const current = String(this.companyField.attr('placeholder') || '');
        const wanted = searchMode ? searchText : manualText;
        const ours = current === searchText || current === manualText;
        if (!ours) {
            return;
        }
        if (current !== wanted) {
            this.companyField.attr('placeholder', wanted);
        }
    }

    /**
     * @returns {string} placeholder wording for manual-entry mode
     */
    static getManualEntryPlaceholderText() {
        return (window.twopayment && window.twopayment.i18n
            && window.twopayment.i18n.company_manual_placeholder)
            || 'Enter your company name';
    }

    /**
     * What opens the panel (§1): a real click on the company-name field, or a
     * keypress on it other than Tab.
     *
     * Focus ALONE does not open it, and that distinction is the requirement
     * verbatim ("note that merely moving focus into it does not open the
     * dropdown - only clicking or typing"). A keyboard buyer tabbing through
     * the address form on their way somewhere else must not have a panel
     * thrown open in front of them.
     *
     * Modifier-only keydowns are ignored for the same reason: Shift on its own
     * is how a buyer starts Shift+Tab, and Shift+Tab is a Tab.
     */
    setupCompanyFieldOpeners() {
        if (!this.companyField || !this.companyField.length) {
            return;
        }
        this.companyField.off('.twoCompanyOpen');

        this.companyField.on('mousedown.twoCompanyOpen', (event) => {
            // NOT guarded on `this._dropdownOpen` (considered and reverted,
            // TWO-40 round 5): clicking the company field again - even while
            // the panel is already open, e.g. with a Sole Trader wait in
            // progress - is the buyer's own deliberate way back to ordinary
            // search (see openDropdown()'s own comment and the "reopening
            // search cancels a pending enrolment" tests). Adding the guard
            // broke that intentional, already-tested behaviour.
            if (this._destroyed || this._manualEntry) {
                return;
            }
            // preventDefault stops the browser placing a caret in (and
            // selecting text of) the readonly field on the way past. Focus is
            // moved into the query field by openDropdown() instead.
            event.preventDefault();
            this.openDropdown();
        });

        this.companyField.on('keydown.twoCompanyOpen', (event) => {
            if (this._destroyed || this._manualEntry || this._dropdownOpen) {
                return;
            }
            const key = event.key;
            if (key === 'Tab' || key === 'Shift' || key === 'Control' || key === 'Alt'
                || key === 'Meta' || key === 'Escape' || key === 'Esc') {
                return;
            }
            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }
            event.preventDefault();
            this.openDropdown();
            // The character that opened the panel belongs in the query field,
            // not lost. Only for a real printable character - `key` is a
            // single code point exactly when the keypress produced text. Not
            // forwarded while the Sole Trader chip is selected (TWO-40
            // follow-up): openDropdown() just set that chip mode when a
            // sole trader is adopted, and the query field is hidden and
            // `readonly` in that state (syncQueryFieldSuppression()) -
            // `.val()` writes through both of those, so this has to check
            // the mode explicitly rather than relying on the field's state.
            if (key && key.length === 1 && this._chipMode !== 'sole_trader'
                && this._queryField && this._queryField.length) {
                this._queryField.val(key);
                this._queryField.trigger('input');
            }
        });
    }

    normalizeCompanyName(value) {
        return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    /**
     * Append the `client`/`client_v` identification params that every call to
     * Two carries, read from the server-published config. The client id and the
     * version are never restated here, so a version bump stays a PHP-only
     * change.
     *
     * Query params rather than a body field, on the POSTs too: that is the
     * convention the module's own server-side calls already use for this pair
     * (getTwoClientParams() / setTwoPaymentRequest() in twopayment.php attach
     * them to the URL on POST and PUT as well as GET).
     *
     * Either param is dropped when the config does not carry it, so a page that
     * somehow runs without the config sends a correct URL rather than a literal
     * `client=undefined`.
     *
     * @param {string} url
     * @returns {string} url with the params appended
     */
    static withTwoClientParams(url) {
        const config = (typeof window !== 'undefined' && window.twopayment) || {};
        const params = new URLSearchParams();
        if (config.client) {
            params.set('client', config.client);
        }
        if (config.client_version) {
            params.set('client_v', config.client_version);
        }
        const query = params.toString();
        if (!query) {
            return url;
        }

        return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
    }

    buildPublicApiBeforeSend() {
        return function (xhr) {
            const blockedHeaders = {
                'authorization': true,
                'proxy-authorization': true,
                'x-api-key': true
            };
            const originalSetRequestHeader = xhr && xhr.setRequestHeader ? xhr.setRequestHeader.bind(xhr) : null;
            if (!originalSetRequestHeader) {
                return;
            }
            xhr.setRequestHeader = function (name, value) {
                const normalized = String(name || '').toLowerCase();
                if (blockedHeaders[normalized]) {
                    return;
                }
                originalSetRequestHeader(name, value);
            };
        };
    }

    clearStaleOrganizationSelection() {
        if (!this.companyField || !this.organizationField) {
            return;
        }

        const company = String(this.companyField.val() || '').trim();
        const orgNumber = String(this.organizationField.val() || '').trim();
        const taggedCompany = String(this.organizationField.attr('data-two-company-name') || '').trim();

        if (!orgNumber) {
            return;
        }

        // Adversarial review round 6 (TWO-25326): all three branches below
        // used to hand-roll a partial clear (organizationField + tag + hint
        // only), the exact shape rounds 4-5 fixed elsewhere in this file -
        // missing the DNI/VAT residue (clearLookupWrittenAddressIdentifiers)
        // and the server session (clearPersistedCompany). Routed through the
        // same clearSelectedCompany() the other fixed call sites use, so
        // this method cannot drift back into that pattern independently.
        if (!company) {
            this.clearSelectedCompany();
            return;
        }

        // If companyid exists but has no selection marker, treat it as stale after address/form re-renders.
        if (!taggedCompany) {
            this.clearSelectedCompany();
            return;
        }

        if (this.normalizeCompanyName(company) !== this.normalizeCompanyName(taggedCompany)) {
            this.clearSelectedCompany();
        }
    }

    setupCompanyInputSync() {
        if (!this.companyField || this.companyField.length === 0) {
            return;
        }

        this.companyField.off('.twoCompanySync');
        this.companyField.on('input.twoCompanySync change.twoCompanySync', () => {
            this.clearStaleOrganizationSelection();
            // Whatever just cleared the tag above changes the answer to
            // hasConfirmedSelection(), which is what §2 gates the "not on the
            // list" button on - so re-evaluate it however the field's value
            // ended up changing.
            this.syncNotListedVisibility();
            this.syncSoleTraderEntryVisibility();
            this.syncRegisteredEntryVisibility();
        });
    }

    setupAddressIdentifierSync() {
        if (!this.companyField || this.companyField.length === 0) {
            return;
        }

        const form = this.companyField.closest('form');
        if (!form || form.length === 0) {
            return;
        }

        form.off('submit.twoAddressIdentifierSync');
        form.on('submit.twoAddressIdentifierSync', () => {
            this.syncOrganizationToAddressIdentifiers();
        });
    }

    syncOrganizationToAddressIdentifiers() {
        if (!this.organizationField || this.organizationField.length === 0) {
            return;
        }

        let orgNumber = String(this.organizationField.val() || '').trim();
        const dniField = $("input[name='dni']");
        const dniValue = dniField.length > 0 ? String(dniField.val() || '').trim() : '';

        // If user already filled DNI manually, reuse it as fallback org number
        // for Two flow. This direction is the customer's own input flowing
        // *into* the Two flow, not the lookup writing out, so it is not gated
        // by the address-lookup toggle.
        if (!orgNumber && dniValue) {
            orgNumber = dniValue;
            this.organizationField.val(orgNumber);

            // Tag it as a confirmed pairing ONLY if `dni` is genuinely the
            // buyer's own value - i.e. not an untouched, lookup-written
            // leftover from a PREVIOUS company (TWO-25288 tile review). A
            // plain retype over a selection clears `companyid` and its tag
            // but leaves a marked, lookup-written `dni` behind (documented
            // residual on PR two-inc/prestashop-plugin#122); adopting that
            // value here and tagging it with whatever name is now in the
            // field would make the payment tile's stale-pairing check treat
            // an unverified adoption as a confirmed one. Once the buyer has
            // gone through proper manual entry (which clears lookup-written
            // fields) or edited `dni` by hand, the marker no longer matches
            // and the value is trustworthy again.
            const dniMarker = dniField.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR);
            const dniIsUntouchedLookupResidue = typeof dniMarker !== 'undefined' && dniMarker === dniValue;
            if (!dniIsUntouchedLookupResidue && this.companyField && this.companyField.length > 0) {
                this.organizationField.attr('data-two-company-name', this.companyField.val() || '');
            }
        }

        if (!orgNumber) {
            return;
        }

        this.writeOrganizationToAddressIdentifiers(orgNumber, true);
    }

    /**
     * Whether the merchant has the address lookup switched on
     * (PS_TWO_ADDRESS_LOOKUP, TWO-25203).
     *
     * This gates only what a company selection *writes* into the address step.
     * Company search itself, and the hidden organisation-number field the Two
     * flow needs, are governed by companySearchInAddressArea and stay live either
     * way.
     */
    isAddressLookupEnabled() {
        return this.config.addressLookupEnabled !== false;
    }

    /**
     * Single gate for the `dni` writes the lookup performs - the
     * selection handler, the company-details refinement, and the pre-submit
     * sync all go through here rather than each carrying its own condition.
     *
     * @param {string} orgNumber
     * @param {boolean} [onlyIfEmpty] Leave a value the customer typed alone.
     * @param {Element} [root] confine the write to one address block. Omitted -
     *        the document-wide default every existing caller relies on.
     * @param {boolean} [bypassAddressLookupGate] Skip isAddressLookupEnabled()
     *        (TWO-40 follow-up, live bug reported by Doug 2026-08-12). That
     *        switch (PS_TWO_ADDRESS_LOOKUP) governs whether an ORDINARY
     *        company-SEARCH selection is allowed to write into the address
     *        step, and `Twopayment::getAddressLookupEnabled()` forces it to
     *        '0' outright whenever company search has moved out of the
     *        address area and into the payment tile - which TWO-40 made the
     *        ONLY place the sole-trader entry point lives. Every shop running
     *        the current design therefore has this switch permanently off,
     *        which silently killed the sole-trader completion's identifier
     *        write with no error and nothing to show for it. adoptSoleTraderBuyer()
     *        passes `true` here for exactly that reason: the write is the
     *        direct, explicit output of an enrolment the buyer just completed,
     *        not a company-search match, so the address-area lookup switch has
     *        nothing to say about it.
     * @returns {boolean} whether the value actually reached a field. Callers that
     *        RECORD the write must take their answer from this and not assume it,
     *        or the record claims a value the form does not hold - which the next
     *        render reads as buyer tampering and pins the whole address on. An
     *        internal (`TWO:`) identifier is skipped here and therefore answers
     *        `false`, which is load-bearing for exactly that reason.
     */
    writeOrganizationToAddressIdentifiers(orgNumber, onlyIfEmpty, root, bypassAddressLookupGate) {
        if (!bypassAddressLookupGate && !this.isAddressLookupEnabled()) {
            return false;
        }

        const value = String(orgNumber || '').trim();
        if (!value) {
            return false;
        }
        // AN INTERNAL (`TWO:`-PREFIXED) IDENTIFIER IS NEVER WRITTEN INTO THE VISIBLE
        // `dni` FIELD (TWO-40, Doug's ruling, Option A). This is the ONE place `TWO:`
        // is treated specially in the write path, and everything else about such a
        // number stays byte-identical to any other: the hidden `companyid`, its
        // `data-two-company-name` pairing tag, the session record, the mirror and the
        // routing are all completely uniform. Only the buyer's own fiscal field is
        // left alone. Three reasons, in order of severity:
        //
        //  1. CORE REFUSES TO SAVE IT. `Address` declares `dni` with
        //     `validate => isDniLite, size => 16`, and `Validate::isDniLite()` is
        //     `/^[0-9A-Za-z-.]{1,16}$/U`. `TWO:ST123456789012` fails that twice - a
        //     colon is not in the character class, and it is 18 characters. Writing
        //     it there makes core reject the address, and the error lands on a field
        //     the plugin was hiding: an invisible, unfixable dead-end at checkout.
        //  2. IT COULD NEVER BE READ BACK. This plugin's own reader,
        //     extractOrgNumberFromAddress(), validates `dni` against
        //     `/^[A-Z0-9\-]{5,20}$/i`, which rejects the colon too. So the value was
        //     write-only even when the write appeared to succeed.
        //  3. IT IS THE WRONG FIELD. `Country::isNeedDniByCountryId()` is purely
        //     country-level, so `dni` is required of EVERY buyer in such a country.
        //     It is the buyer's own fiscal number (NIF/CIF), not a slot for our
        //     identifier. The buyer fills it themselves, which is why leaving it
        //     alone blocks nobody - the required field still gets satisfied, by its
        //     rightful owner.
        //
        // This is NOT the earlier reverted approach. That one also withheld the
        // pairing and the name, sending a sole trader's selection down a different
        // path through storage, pairing, mirroring and submission - and every defect
        // that followed came from that divergence. Here only this one field is
        // skipped.
        //
        // `window.TwoCompanyNumber` is dereferenced UNGUARDED, matching every other
        // use of it in this file. A feature-test would fail OPEN - i.e. would write
        // the `TWO:` value into `dni` on the very load where the helper failed to
        // arrive - which is the outcome this gate exists to prevent.
        if (window.TwoCompanyNumber.isInternal(value)) {
            // `false`, not a bare `return`. The invoice mirror takes `wroteNumber`
            // from this answer; recording a write that did not happen makes the next
            // render read the empty field as buyer tampering and pin the whole
            // secondary address.
            return false;
        }

        let wrote = false;
        this.addressIdentifierFields(root).forEach(field => {
            if (field.length === 0) {
                return;
            }
            if (onlyIfEmpty && String(field.val() || '').trim() !== '') {
                // Buyer input. Deliberately NOT marked: the marker's whole
                // meaning is "the lookup put this here", and mislabelling the
                // buyer's own number would let clearSelectedCompany() delete it.
                return;
            }
            field.val(value);
            field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR, value);
            wrote = true;
        });

        return wrote;
    }

    // THERE IS NO VISIBILITY RULE FOR THE IDENTIFICATION FIELD HERE, AND ADDING
    // ONE BACK WOULD BE A MISTAKE (TWO-40, Option A). A previous round hid the
    // field's `.form-group` whenever it held an internal (`TWO:`) identifier.
    // Two reasons it is gone:
    //
    //  1. Nothing puts such a value there any more - see
    //     writeOrganizationToAddressIdentifiers() - so there is nothing to hide.
    //     Whatever `dni` holds is the buyer's own fiscal number, which is theirs
    //     to see and to correct.
    //  2. The hiding could never have been COMPLETE. Core renders `dni` into
    //     address blocks, invoice PDFs and order confirmation emails via
    //     AddressFormat::generateAddress(), none of which any CSS rule of ours
    //     reaches. A checkout-only hide would have been a false sense of one.

    /**
     * The address inputs a company selection mirrors its organisation number
     * into.
     *
     * `dni` ("Identification number") only. **`vat_number` is deliberately NOT
     * in this list and must never be added back.** An organisation number is
     * not a VAT number: the two identifiers have different formats, different
     * issuing registers, and a company can hold the first without ever holding
     * the second. Writing the org number into the VAT field puts a value the
     * buyer never gave into a field the buyer is answerable for, and it is
     * wrong even when the two strings happen to coincide.
     *
     * It also has a silent side effect on tax. The shop reads a non-empty
     * `vat_number` on a foreign address as a B2B reverse-charge exemption -
     * the same condition core's price calculation switches tax off with - so a
     * mirrored org number can zero the resolved VAT rate on an order whose
     * buyer is not VAT-registered at all.
     *
     * One list rather than the selector written twice, because the clear has to
     * walk exactly the fields the write walks. A field present in one list and
     * absent from the other is a disowned organisation number left in the form.
     *
     * Document-wide by DEFAULT, which is what every caller but the invoice mirror
     * wants: there is only ever one editable address form on a PrestaShop
     * checkout, so an unscoped read is unambiguous there. The mirror passes a root
     * because it writes as a PAIR into one specific block and must not widen that
     * to the document - and the default is left exactly as it was rather than
     * narrowed for everyone, because narrowing it silently would change callers
     * that run on pages where the org-number field is not inside an address block
     * at all.
     *
     * @param {Element} [root] confine the lookup to one address block
     * @returns {Array<Object>} jQuery objects, any of which may be empty
     */
    addressIdentifierFields(root) {
        if (root) {
            return [$(root).find("input[name='dni']").first()];
        }

        return [$("input[name='dni']")];
    }

    /**
     * Drop the identification numbers, but ONLY the ones the lookup itself
     * wrote and the buyer has not since changed.
     *
     * Not a blanket clear, and that constraint is what makes this method
     * necessary rather than a one-line addition to clearSelectedCompany(). A
     * buyer-typed identification number is legitimate and load-bearing: it is
     * the only route by which a manual-entry buyer's own number reaches the Two
     * flow, via syncOrganizationToAddressIdentifiers() adopting it as the
     * organisation number at submit. Clearing it would delete the buyer's answer.
     *
     * So the same marker the address autofill uses distinguishes the two: it
     * records the exact value written, and a buyer edit leaves it stale rather
     * than matching. Same attribute deliberately - the semantics are identical
     * and these fields never overlap the ones autoFillAddress() walks, so one
     * vocabulary is better than two that have to be kept in step.
     *
     * @returns {void}
     */
    clearLookupWrittenAddressIdentifiers() {
        this.addressIdentifierFields().forEach(field => {
            if (field.length === 0) {
                return;
            }
            const written = field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR);
            if (typeof written === 'undefined') {
                return;
            }
            if (written !== String(field.val() == null ? '' : field.val())) {
                return;
            }
            field.removeAttr(TwoCompanySearch.AUTOFILL_MARKER_ATTR);
            field.val('');
            field.trigger('input');
            field.trigger('change');
        });
        // No visibility pass to undo here: this class never hides the
        // identification field, because it never writes an internal (`TWO:`)
        // identifier into it.
    }

    /**
     * Whether the buyer's current state says their invoice address is a
     * DIFFERENT address from the one they are shipping to (TWO-40).
     *
     * Named and worded for what the buyer has STATED, never for the state of a
     * checkbox, and that is a requirement rather than a preference. Two reasons,
     * both of which mislead anyone who reads this as "is the box ticked":
     *
     *  1. PrestaShop offers the checkbox on the FIRST pass only, while the
     *     delivery form is being edited, and its polarity is inverted from the
     *     question asked here - CHECKED means the two addresses are the SAME. On
     *     every later pass there is no checkbox at all: core renders a link
     *     ("billing address differs from shipping address") whose href navigates,
     *     so the invoice side is revealed by a page load and there is no
     *     client-side toggle to observe.
     *  2. Another platform in this plugin family expresses the same buyer
     *     statement with a checkbox of the OPPOSITE polarity, so an engineer
     *     porting this and reading "checked" here would wire it up backwards.
     *     The abstraction exists to make that impossible.
     *
     * Resolution, deliberately polarity-neutral in the path that matters:
     *
     *  1. if the shared-address control is in the DOM at all, the buyer's live
     *     statement is its NEGATION - it asks the opposite question;
     *  2. else, the presence of an invoice block of either shape - an editable
     *     form or a selector over saved addresses - IS the statement, because
     *     core renders that block only when the two addresses are not shared;
     *  3. else false. An unclear signal is not evidence that the addresses
     *     differ, and everything downstream of this treats false as a no-op.
     *
     * @returns {boolean}
     */
    buyerStatesInvoiceAddressDiffers() {
        const sharedAddressControl = document.querySelector("input[name='use_same_address']");
        if (sharedAddressControl) {
            return !sharedAddressControl.checked;
        }

        // `.js-invoice-address` is a tolerance for themes that keep core's
        // structure but not its ids. Nothing else is accepted: these are the
        // shapes core's own templates emit, and inventing selectors it never
        // emits would make this answer true on pages where it is false.
        return !!document.querySelector('#invoice-address, #invoice-addresses, .js-invoice-address');
    }

    /**
     * Which address the one editable form on the page is for - 'delivery',
     * 'invoice', or '' when there is no editable form (TWO-40).
     *
     * Read from the hidden field core's address form emits carrying exactly that
     * word. There is only ever ONE editable address form on a PrestaShop
     * checkout - the flags for the two are set in mutually exclusive branches -
     * so a single unscoped read is unambiguous.
     *
     * @returns {string}
     */
    visibleAddressFormType() {
        const marker = document.querySelector("input[name='saveAddress']");
        if (!marker) {
            return '';
        }
        return String(marker.value || '').trim().toLowerCase();
    }

    /**
     * The address blocks core's addresses step can render - the editable form for
     * either side, and the radio selector over saved addresses that stands in for
     * the other side.
     *
     * Used to recognise a candidate scope that is really the STEP: anything with
     * one of these INSIDE it spans more than one address, and is not a scope.
     *
     * The ids alone are NOT enough, and an id-only list missed the guard's own
     * motivating case (TWO-40, round 6). A theme is free to drop core's ids while
     * keeping the rest of its markup, and then the widest candidate - the step's
     * outer wrapper, which core emits itself - looks blockless while still
     * containing the other address. So this also names every CLASS core puts on a
     * saved-address selector and on the address items inside it, and the radio
     * that carries the other address's id. That radio is the sturdiest of the
     * three kinds of marker: it is a form field name, not a styling hook or a
     * document id, so a theme cannot drop it without breaking its own submission.
     *
     * Nothing here can reject a legitimate scope: not one of these appears
     * anywhere in the markup of core's address FORM
     * (`customer/_partials/address-form.tpl` and the checkout override of it),
     * which is all a correctly resolved scope ever contains.
     */
    static ADDRESS_BLOCK_SELECTOR = [
        // The four block ids `checkout/_partials/steps/addresses.tpl` emits.
        '#delivery-address',
        '#delivery-addresses',
        '#invoice-address',
        '#invoice-addresses',
        // The classes it emits on the two selector blocks, and the ones
        // `address-selector-block.tpl` emits on each saved address inside them.
        '.address-selector',
        '.js-address-selector',
        '.js-address-item',
        '.address-item',
        // The radio naming the other address. Load-bearing, not decorative.
        "input[name='id_address_delivery']",
        "input[name='id_address_invoice']",
        // A tolerance for themes that keep core's structure but not its ids.
        '.js-invoice-address',
    ].join(', ');

    /**
     * The element to scope the visible address form's field lookups to, or null.
     *
     * Innermost-first, because core nests the rendered address form's own
     * `<form>` inside the step's outer one (HTML drops the inner tag, so the
     * block element is the reliable boundary, not the form).
     *
     * FAILS CLOSED, and that is the point of the second half of this method
     * (TWO-40, round 5). The candidate list used to end in `form`, so a theme whose
     * markup does not carry the block ids resolved to the step's OUTER form - which
     * contains BOTH address blocks, and writing into it is precisely the
     * document-wide write this whole feature exists to prevent. The same is true of
     * the outer `.js-address-form` wrapper, which core itself emits around the
     * whole step. So a candidate that CONTAINS another address block is rejected
     * outright rather than used: no scope means no mirror, which is a visible
     * no-op, where a widened scope is a silent write into an address the buyer is
     * not looking at.
     *
     * Note the guard bites exactly when it matters: a page whose only address block
     * is the visible form has nothing else for a wide scope to reach, and resolves
     * normally.
     *
     * @returns {?Element}
     */
    visibleAddressFormRoot() {
        const marker = document.querySelector("input[name='saveAddress']");
        if (!marker || typeof marker.closest !== 'function') {
            return null;
        }
        const root = marker.closest(
            '#invoice-address, #delivery-address, .js-invoice-address, .js-address-form'
        );
        if (!root || typeof root.querySelector !== 'function') {
            return null;
        }
        if (root.querySelector(TwoCompanySearch.ADDRESS_BLOCK_SELECTOR)) {
            return null;
        }

        return root;
    }

    /**
     * The scope of the SECONDARY address - the one PrestaShop does not have the
     * buyer edit by default - or null when the buyer is not looking at it (TWO-40).
     *
     * On PrestaShop the address playing the billing/invoice role is the secondary
     * one, so the two coincide here. That is a platform fact and not a general one:
     * another platform in this family is billing-FIRST, and there its billing
     * address is the PRIMARY. Anything phrased in terms of the billing ROLE ports;
     * anything phrased in terms of primary/secondary position does not.
     *
     * Three conditions, all of them about what the buyer has stated rather than
     * about any control's state:
     *
     *  - the one editable form on the page is the invoice one. On the shipping pass
     *    there is no secondary address in the document at all;
     *  - the buyer's current selection indicates the two addresses are different
     *    ones. When they say the addresses are the same there is one address, and
     *    there is no secondary to write into or to protect;
     *  - the form resolves to a scope that does not span another address block.
     *    Fails closed: no scope means no write.
     *
     * @returns {?Element}
     */
    secondaryAddressFormRoot() {
        if (this.visibleAddressFormType() !== 'invoice') {
            return null;
        }
        if (!this.buyerStatesInvoiceAddressDiffers()) {
            return null;
        }

        return this.visibleAddressFormRoot();
    }

    /**
     * The fields of the secondary address whose contents decide whether it is still
     * in sync (TWO-40), in the short-name vocabulary the server's mirror-write
     * record uses (`Twopayment::MIRROR_WRITE_SESSION_KEYS`).
     *
     * These are exactly the fields the plugin can ATTRIBUTE. A value in one of them
     * is either one the mirror or the ordinary company lookup put there - in which
     * case the last-written record says so - or one the buyer authored. Every other
     * field of a PrestaShop address (the name fields, the phone) is one the plugin
     * never writes, so every value in it is buyer-authored by definition: counting
     * them would pin the secondary address the moment the buyer typed the name they
     * are obliged to type before they can save it at all, on the very first render.
     */
    static MIRRORED_ADDRESS_FIELDS = [
        'company',
        'organization',
        'country',
        'address1',
        'address2',
        'postcode',
        'city',
        'state',
    ];

    /**
     * What the mirror last wrote into the secondary address on this cart, as the
     * server published it (TWO-40).
     *
     * This, and not the primary address's live value, is the comparison basis for
     * the pin - see `Twopayment::MIRROR_WRITE_SESSION_KEYS` for why comparing
     * against the primary is provably self-defeating.
     *
     * @returns {Object} short field name to last-written value; missing keys mean
     *          nothing was ever written there
     */
    persistedMirrorWrites() {
        const published = (window.twopayment && window.twopayment.mirror_writes) || null;
        if (!published || typeof published !== 'object') {
            return {};
        }

        return published;
    }

    /**
     * Trim, and fold case. Both are Doug's ruling on how a content match is
     * decided: the buyer retyping "acme trading ltd" over "Acme Trading Ltd", or
     * leaving a trailing space behind, has not authored a different answer.
     *
     * @param {*} value
     * @returns {string}
     */
    normalizeMirroredValue(value) {
        return String(value == null ? '' : value).trim().toLowerCase();
    }

    /**
     * The ISO code an option VALUE in a country select stands for, or ''.
     *
     * The inverse of countryOptionValueForIso(), with the same three resolution
     * strategies in the same order, because the two have to agree. Comparisons are
     * made on the ISO and never on the option's visible text: the id is shop-local
     * and the label is locale-dependent, so either one would make the record
     * unreadable on a shop or a language other than the one that wrote it.
     *
     * @param {HTMLSelectElement} select
     * @param {string} optionValue
     * @returns {string} uppercase alpha-2, or ''
     */
    countryIsoForOptionValue(select, optionValue) {
        const value = String(optionValue == null ? '' : optionValue).trim();
        if (!value || !select || !select.options) {
            return '';
        }
        for (let index = 0; index < select.options.length; index++) {
            const option = select.options[index];
            if (option.value !== value) {
                continue;
            }
            const attrIso = option.getAttribute('data-iso-code')
                || option.getAttribute('data-iso')
                || option.getAttribute('data-country-iso');
            if (attrIso) {
                return String(attrIso).toUpperCase();
            }
            const mapped = (window.twopayment && window.twopayment.countries)
                ? window.twopayment.countries[option.value]
                : null;
            if (mapped) {
                return String(mapped).toUpperCase();
            }
            return this.extractCountryFromText(String(option.textContent || '')) || '';
        }

        return '';
    }

    /**
     * Every comparable field of the secondary address, paired with the values the
     * plugin has on record as having written there (TWO-40).
     *
     * A field the form does not have is not in the list: there is nothing to
     * compare, and treating an absent field as a mismatch would pin every address
     * whose country's format has no identification field.
     *
     * Two sources of "what we last wrote", and both are consulted:
     *
     *  - the field's own marker attribute, which is this PAGE's record. It is the
     *    only one that exists for a write made since the last render, and it is
     *    destroyed by core's form rebuild;
     *  - the cart-scoped record the server published, which is the only one that
     *    survives a page load - and the pin is evaluated on a page load.
     *
     * Each state also carries the field's UNANSWERED value, which counts only while
     * nothing at all is on record as having been written there. For a text input
     * that is the empty string. For the country select it is whatever the server
     * rendered as selected - see serverRenderedSelectValue() for why an empty
     * country select does not exist on a real PrestaShop form, so emptiness is not
     * an available test there.
     *
     * The "only while nothing was written" condition is what makes the country
     * pinnable at all: core re-renders the form on every country change, so the
     * value the server rendered is, after the first change, always the value the
     * buyer just chose. Accepting it unconditionally would mean the country could
     * never read as buyer-authored.
     *
     * Text inputs deliberately get no server-rendered baseline of their own: a
     * non-empty street the server rendered is a street the buyer's saved address
     * owns, and treating it as unanswered is exactly how an existing billing address
     * gets silently overwritten.
     *
     * @param {Element} root the secondary address form's scope
     * @returns {Array<{name: string, current: string, written: Array<string>,
     *          unanswered: string}>}
     */
    mirroredAddressFieldStates(root) {
        const persisted = this.persistedMirrorWrites();
        const states = [];
        const record = (name, field, current, unanswered, convert) => {
            if (!field || field.length === 0) {
                return;
            }
            const marker = field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR);
            const written = [];
            if (typeof marker !== 'undefined') {
                written.push(typeof convert === 'function' ? convert(marker) : marker);
            }
            if (typeof persisted[name] !== 'undefined') {
                written.push(persisted[name]);
            }
            states.push({
                name: name,
                current: current,
                written: written.filter(value => String(value == null ? '' : value) !== ''),
                unanswered: unanswered || ''
            });
        };
        const liveValue = field => String(field.val() == null ? '' : field.val());

        const companyField = $(root).find("input[name='company']").first();
        record('company', companyField, liveValue(companyField), '');

        this.addressIdentifierFields(root).forEach(field => {
            record('organization', field, liveValue(field), '');
        });

        // `address2` is here because the sole-trader autofill routes building and
        // apartment into it (TWO-40, Doug's ruling): a buyer typing a second address
        // line is stating an independent answer exactly as much as one typing a city,
        // so it pins the address like any other field. Omitting it would have made
        // the address-wide rule miss a real case of buyer-entered data.
        ['address1', 'address2', 'postcode', 'city'].forEach(name => {
            const field = $(root).find(`input[name='${name}']`).first();
            record(name, field, liveValue(field), '');
        });

        // The state/county select, where the autofill routes `region` on countries
        // that have one. Compared on the option's TEXT rather than its value, for the
        // same reason the country is compared on ISO: the id is shop-local, so a
        // record written on one shop would be unreadable on another. Treated like the
        // country select in the other respect too - "unanswered" means still the
        // value the server rendered, because a select is never empty.
        const stateSelect = $(root).find("select[name='id_state'], select[name='state']").first();
        if (stateSelect.length > 0) {
            const toName = value => this.stateNameForOptionValue(stateSelect[0], value);
            // "Unanswered" is EMPTY for a state select, NOT "still what the server
            // rendered" - the opposite of the country beside it, and deliberately so.
            // The country select has no reachable empty state, which is the entire
            // justification for treating its server-rendered value as unanswered. A
            // state select DOES have a reachable empty placeholder, so a
            // server-rendered state is the buyer's own saved answer and must pin the
            // address like any other answer of theirs.
            record(
                'state',
                stateSelect,
                toName(liveValue(stateSelect)),
                '',
                toName
            );
        }

        const select = $(root).find("select[name='id_country'], select[name='country']").first();
        if (select.length > 0) {
            const toIso = value => this.countryIsoForOptionValue(select[0], value);
            record(
                'country',
                select,
                toIso(liveValue(select)),
                toIso(this.serverRenderedSelectValue(select[0])),
                toIso
            );
        }

        return states;
    }

    /**
     * Whether one field still holds what the plugin last put there.
     *
     * @param {{current: string, written: Array<string>, unanswered: string}} state
     * @returns {boolean}
     */
    mirroredFieldStillHoldsWhatWeWrote(state) {
        const current = this.normalizeMirroredValue(state.current);
        if (state.written.some(value => this.normalizeMirroredValue(value) === current)) {
            return true;
        }
        if (state.written.length > 0) {
            // Something of ours was written here and this is not it. Includes the
            // field having been emptied: the buyer deleting our value is an edit like
            // any other, and refilling it on the next render would be the plugin
            // arguing with them.
            return false;
        }

        // Nothing was ever written here, so "still holds what we wrote" is decided by
        // whether the buyer has answered the field at all. This is the ordinary state
        // of a brand-new billing address form.
        return current === '' || current === this.normalizeMirroredValue(state.unanswered);
    }

    /**
     * The values a mirrored write into one field may overwrite, besides one the
     * field's own marker still claims (TWO-40).
     *
     * The same rule the pin applies, expressed for the per-field write layer so the
     * two cannot drift: the value on record as last written there, and - only when
     * there is none - that field's unanswered value.
     *
     * @param {string} recordName short field name in the mirror-write record
     * @param {string} [unansweredValue] what counts as unanswered for this field
     * @param {Function} [convert] maps a recorded value into the field's own
     *        vocabulary, for a field that does not store what it displays
     * @returns {Array<string>}
     */
    mirrorWriteAcceptedValues(recordName, unansweredValue, convert) {
        const recorded = this.persistedMirrorWrites()[recordName];
        const value = String(recorded == null ? '' : recorded);
        if (value !== '') {
            const mapped = typeof convert === 'function' ? convert(value) : value;
            return mapped ? [String(mapped)] : [];
        }

        return unansweredValue ? [String(unansweredValue)] : [];
    }

    /**
     * Whether the secondary address is PINNED - the buyer has made it their own,
     * and nothing may be written into any of its fields (TWO-40).
     *
     * ADDRESS-WIDE, not per-field, and that is Doug's ruling rather than an
     * implementation convenience: any address field the buyer has entered pins the
     * address, and the test for "entered" is a content match. Put together, ONE
     * field that no longer holds what the plugin put there pins the WHOLE secondary
     * address and no field is synced.
     *
     * The consequence, stated plainly because it is the behaviour and not a corner:
     * the mirror only ever writes into a PRISTINE secondary address, and once the
     * buyer touches anything in it, it stays frozen for the rest of the cart unless
     * the contents come back to matching.
     *
     * @param {Element} root the secondary address form's scope
     * @returns {boolean}
     */
    secondaryAddressIsPinned(root) {
        return this.mirroredAddressFieldStates(root).some(
            state => !this.mirroredFieldStillHoldsWhatWeWrote(state)
        );
    }

    /**
     * Report what the mirror has just written into the secondary address, so the
     * NEXT page load can still tell those values from ones the buyer authored
     * (TWO-40).
     *
     * Fire-and-forget, like clearPersistedCompany() beside it, and the failure mode
     * is deliberately the safe one: a request that never arrives leaves the next
     * render seeing non-empty fields with nothing on record as having written them,
     * which reads as buyer-authored and PINS the address. A lost report costs one
     * missed re-sync; the opposite default would cost the buyer's own data.
     *
     * Takes a partial record. A field the caller does not mention is left exactly as
     * it was, so a country-only write does not have to republish the company. An
     * empty string IS reported and IS meaningful: it says nothing of ours is in that
     * field any more.
     *
     * @param {Object} values keyed by MIRRORED_ADDRESS_FIELDS names
     * @returns {boolean} whether anything was reported
     */
    recordMirrorWrites(values) {
        const written = {};
        Object.keys(values || {}).forEach(name => {
            if (TwoCompanySearch.MIRRORED_ADDRESS_FIELDS.indexOf(name) === -1) {
                return;
            }
            written[name] = String(values[name] == null ? '' : values[name]);
        });
        if (Object.keys(written).length === 0) {
            return false;
        }

        // Keep the published copy in step, so a second evaluation on THIS page
        // reaches the same answer the server will give on the next one. Without
        // this, the re-mount that core's own rebuild triggers would judge the
        // mirror's own fresh writes against a record that predates them.
        if (window.twopayment) {
            window.twopayment.mirror_writes = Object.assign(
                {}, this.persistedMirrorWrites(), written
            );
        }

        try {
            if (!window.twopayment || !window.twopayment.order_intent_url || !window.twopayment.ajax_token) {
                return false;
            }
            $.ajax({
                url: window.twopayment.order_intent_url,
                method: 'POST',
                data: Object.assign({
                    ajax: 1,
                    action: 'saveMirrorWrites',
                    token: window.twopayment.ajax_token
                }, written),
                timeout: 10000
            });
        } catch (e) {
            // no-op
        }

        return true;
    }

    /**
     * Carry a company selection made on the shipping pass over to the invoice
     * address form (TWO-40). Company NAME and COUNTRY only.
     *
     * This is a CROSS-PAGE-LOAD operation, and it has to be: PrestaShop never
     * renders two editable address forms at once, so at the moment the buyer
     * picks a company there are no invoice fields in the document to write into.
     * The invoice form arrives later, on its own page load, and this runs when it
     * does - at mount, from init(). There is no reveal event to listen for.
     *
     * Nothing happens unless all of these hold:
     *
     *  - the merchant has address population switched on. This writes into the
     *    address form, so it belongs behind the address-population switch like
     *    every other write here - which also makes it inert on the payment tile
     *    mount, where that switch is forced off;
     *  - the editable form on screen is the INVOICE one. On the shipping pass
     *    there is nothing to carry anything to yet;
     *  - the buyer's current state says the two addresses differ. When they do
     *    not there is one address and nothing to mirror, and this is a true
     *    no-op - it does not populate anything speculatively;
     *  - a company selection exists for this cart.
     *
     * Street, postcode and city are deliberately NOT mirrored. The buyer has
     * just said this is a different address; its street is legitimately not the
     * company's registered one, and writing it would be the plugin overruling
     * a statement the buyer made explicitly.
     *
     * FOUR SEPARATE OPERATIONS, and keeping them separate is the whole design:
     *
     *  - RE-MARK (reapplyMirrorMarkers) re-establishes the autofill marker on a
     *    value still recognisable as one this page's mirror wrote. It never writes
     *    a value and never touches an empty field. It exists because a successful
     *    country write triggers core's own form rebuild: core's `.js-country`
     *    handler POSTs `action=addressForm`, replaces every `.js-address-form`
     *    with the response, and restores the previous values with an INPUT-only
     *    loop that copies values and not attributes. The company name survives;
     *    its marker does not. Without a re-mark the plugin reads its own write as
     *    buyer-typed and can no longer disown it.
     *  - POPULATE (populateInvoiceAddressFromConfirmedCompany) fills unanswered
     *    fields, at most once per company per page. It must NOT re-run after that,
     *    because the same rebuild is how a buyer's deliberate CLEAR comes back
     *    round: cleared field, country change, form replaced, empty value
     *    restored, search rebuilt, init() again - and a populate keyed only on
     *    "the field is empty" would silently undo the clear.
     *  - COMPLETE (completeMirroredOrganizationNumber) places the NUMBER half
     *    when the rebuild is what separated it from the name. It exists because
     *    the identification field's presence is decided by the COUNTRY: core
     *    appends `dni` to the address format only when the country carries
     *    `need_identification_number`, so the mirror's own country write can hand
     *    back a form with an identification field the previous render did not have
     *    (or take one away, which core's INPUT-only restore loop cannot put back).
     *    Left to the once-per-company populate gate, that is a company name on the
     *    order with no organisation number beside it.
     *  - RE-PUBLISH (republishMirroredSelection) puts the hidden `companyid` field
     *    and its pairing tag back after the same rebuild destroys them. Separate
     *    because it is about the plugin's own selection bookkeeping rather than
     *    about any address field, and because it must run whatever the other three
     *    decided.
     *
     * Re-mark first, so a populate for a genuinely NEW company can still
     * recognise the previous mirror's values as ours rather than as the buyer's.
     * Complete before the re-publish, so a populate that has just placed the pair
     * for a new company settles the number half before the completion looks at it,
     * and the re-publish sees the settled memory.
     *
     * The mirror deliberately does NOT publish the selection to
     * TwoCheckoutManager. The selection it is acting on has just been RESTORED
     * from the server's cart-scoped record, and setConfirmedCompanySelection()
     * re-derives the captured address and country from the CURRENT page - which is
     * precisely what seedConfirmedCompanySelectionFromServer() exists to avoid,
     * because it would stamp the record with the page it is being restored onto
     * and neuter both invalidation checks it must remain subject to.
     *
     * @returns {void}
     */
    mirrorConfirmedCompanyToInvoiceAddress() {
        if (this._destroyed) {
            return;
        }
        if (!this.isAddressLookupEnabled()) {
            return;
        }
        const root = this.secondaryAddressFormRoot();
        if (!root) {
            return;
        }

        this.reapplyMirrorMarkers(root);
        // THE PIN, address-wide, and the gate in front of the populate only. The
        // other three operations are rebuild REPAIR rather than sync: none of them
        // introduces a value the mirror has not already placed on this page.
        //
        // Two of them are genuinely inert on a pinned address, for their own
        // reasons: RE-MARK never writes a value at all, and RE-PUBLISH restores the
        // hidden pair only behind a company name that is still, exactly, the marked
        // one the mirror recorded writing - which a pin caused by the company field
        // rules out.
        //
        // COMPLETE is NOT inert on a pinned address, and that is deliberate rather
        // than an oversight (TWO-40, round 1 of the content-match rework). A pin
        // raised by a DIFFERENT field - a city the buyer typed - leaves the marked
        // company name untouched, so the completion still fires there. It is
        // bounded instead of gated: it writes only the NUMBER half of a pair the
        // plugin itself created, only while the name half is still the mirror's own
        // marked value, and only into an identification field that is empty and
        // carries no marker of any kind. Gating it on the pin would put back the
        // defect it was written to close - a mirrored company name reaching the
        // order payload with no organisation number beside it, on a form where core
        // requires one - and protecting that pair is worth more than the pin's
        // reach here, because an empty unmarked field holds no answer of the
        // buyer's to protect.
        if (!this.secondaryAddressIsPinned(root)) {
            this.populateInvoiceAddressFromConfirmedCompany(root);
        }
        this.completeMirroredOrganizationNumber(root);
        this.republishMirroredSelection();
    }

    /**
     * Put the hidden `companyid` field and its pairing tag back when core's
     * rebuild has taken them away, but the mirror's own name is still in the form.
     *
     * The FOURTH operation, and not an optional tidy-up: the hidden field the
     * mirror publishes through lives inside `.js-address-form` (it is inserted
     * after the company input), so core's country-change rebuild destroys it, and
     * its INPUT-only restore loop cannot put back a field the new render does not
     * emit. init() then builds a fresh EMPTY one. Since the mirror's own country
     * write is what triggers that rebuild, this is the ordinary path rather than a
     * corner: without this, every mirrored selection is invisible to
     * clearStaleOrganizationSelection() from the first country write onwards -
     * which is the whole defect the mirror's publish path exists to close.
     *
     * Gated exactly as completeMirroredOrganizationNumber() is - on the name in
     * the form still being, exactly, the marked one the mirror recorded writing -
     * so a name the buyer has since made their own never gets an organisation
     * number re-attached to it.
     *
     * @returns {boolean} whether the pair was re-published
     */
    republishMirroredSelection() {
        const memory = this.mirrorMemory();
        const recordedName = memory.company ? String(memory.company) : '';
        const number = String(memory.organization || memory.organizationPending || '');
        if (!recordedName || !number) {
            return false;
        }
        if (!this.companyField || this.companyField.length === 0) {
            return false;
        }
        const name = String(this.companyField.val() == null ? '' : this.companyField.val());
        if (name !== recordedName
            || this.companyField.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR) !== name) {
            return false;
        }

        return this.markOrganizationFieldSelected(recordedName, number);
    }

    /**
     * Where the mirror records what it has already done, for this page.
     *
     * The manager injects its own object, because the manager outlives the search:
     * it destroys and rebuilds this instance on every `updatedAddressForm`, so a
     * record kept on the instance would be gone exactly when it is needed. Falls
     * back to an instance-local object so a search mounted without a manager still
     * behaves, rather than throwing.
     *
     * The two halves of the pair are recorded SEPARATELY and deliberately:
     * `organization` is a number the mirror has PLACED in an identification field
     * on this page, `organizationPending` one it has not been able to place in any
     * field yet. Under one key they cannot be told apart, and the completion below
     * needs exactly that distinction - a placed number the buyer then cleared must
     * stay cleared, while an unplaced one still has to reach the form.
     *
     * @returns {Object} `{companyid, company, organization, organizationPending,
     *          countryValue}`, partly filled
     */
    mirrorMemory() {
        const injected = this.config.mirrorMemory;
        if (injected && typeof injected === 'object') {
            return injected;
        }
        if (!this._ownMirrorMemory) {
            this._ownMirrorMemory = {};
        }
        return this._ownMirrorMemory;
    }

    /**
     * Re-establish the autofill marker on values still recognisable as this
     * page's own mirrored writes. NEVER writes a value.
     *
     * The operation exists because core strips the marker. Its rule is
     * deliberately narrow: the field's current value must equal, exactly, what
     * the mirror recorded writing there. A field the buyer has since edited does
     * not match and is not claimed; nor is one core re-rendered with a different
     * value, which is what a country select looks like after the buyer changes
     * country - so the buyer's new country is never re-marked as ours.
     *
     * @param {Element} root the visible address form's scope
     * @returns {boolean} whether any marker was re-established
     */
    reapplyMirrorMarkers(root) {
        const memory = this.mirrorMemory();
        if (!memory.companyid) {
            return false;
        }

        const remark = (field, recorded) => {
            const value = String(recorded == null ? '' : recorded);
            if (!value || !field || field.length === 0) {
                return false;
            }
            const current = String(field.val() == null ? '' : field.val());
            if (current !== value) {
                return false;
            }
            if (field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR) === current) {
                return false;
            }
            field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR, current);

            return true;
        };

        let reapplied = remark($(root).find("input[name='company']").first(), memory.company);
        if (memory.organization && this.addressIdentifierFields(root).every(
            field => !field || field.length === 0
        )) {
            // The rebuild rendered a country whose address format has NO
            // identification field, and core's restore loop cannot restore a field
            // the new render does not emit - so the number the mirror had placed is
            // now placed nowhere. Back to pending, not dropped: the buyer can
            // return to a country that does have the field, and the name is still
            // in the form waiting for its number.
            //
            // Only from the field being ABSENT, never from a present field whose
            // value no longer matches. That second case is the buyer having
            // cleared or changed the number, and it must stay their answer.
            memory.organizationPending = memory.organization;
            memory.organization = '';
        }
        this.addressIdentifierFields(root).forEach(field => {
            // The organisation number needs this as much as the name does: the
            // marker is what clearLookupWrittenAddressIdentifiers() uses to tell
            // a number the lookup wrote from one the buyer typed, and losing it
            // leaves a disowned number in the form that nothing may delete.
            reapplied = remark(field, memory.organization) || reapplied;
        });
        const countrySelect = $(root).find("select[name='id_country'], select[name='country']").first();
        reapplied = remark(countrySelect, memory.countryValue) || reapplied;

        return reapplied;
    }

    /**
     * Fill the invoice form's unanswered company fields from the confirmed
     * selection, at most once per company per page.
     *
     * The once-per-company rule is recorded on the page-lifetime memory rather
     * than inferred from the DOM, because the DOM cannot answer the question: a
     * field the buyer deliberately cleared and an unanswered field are the same
     * empty string. Keyed on the organisation number so that a buyer who picks a
     * genuinely DIFFERENT company on this page is still mirrored.
     *
     * @param {Element} root the visible address form's scope
     * @returns {boolean} whether anything was written
     */
    populateInvoiceAddressFromConfirmedCompany(root) {
        const selection = this.confirmedCompanyForMirror();
        if (!selection) {
            return false;
        }
        const memory = this.mirrorMemory();
        if (memory.companyid && memory.companyid === selection.companyid) {
            return false;
        }

        // Scoped to the form element, never document-wide: a write by global
        // selector with no awareness of which block it landed in is the defect
        // class this whole feature has to avoid.
        const companyField = $(root).find("input[name='company']").first();
        const identifierFields = this.addressIdentifierFields(root).filter(
            field => field && field.length > 0
        );

        // The NAME and the NUMBER travel together, or neither travels.
        //
        // The order payload is why. Once the buyer saves this address, the
        // resolver can reach the tier that reads the company off the ADDRESS -
        // `company` for the name, the identification field for the number - and a
        // mirrored name with no number beside it means an order carrying a company
        // the buyer never typed and no organisation number at all. That is worse
        // than not mirroring, so a form whose identification field already holds
        // the buyer's own number gets neither write.
        //
        // A form with NO identification field is a different case and does get the
        // name: the field's presence is decided by the country's address format,
        // there is nowhere to put a number on such a form, and the ordinary
        // company lookup has always behaved this way on those countries.
        if (!this.mirrorTargetIsWritable(companyField, this.mirrorWriteAcceptedValues('company'))) {
            return false;
        }
        const identifierAccepted = this.mirrorWriteAcceptedValues('organization');
        const blocked = identifierFields.some(
            field => !this.mirrorTargetIsWritable(field, identifierAccepted)
        );
        if (blocked) {
            return false;
        }

        const wroteCompany = this.writeMirroredValue(
            companyField, selection.company, this.mirrorWriteAcceptedValues('company')
        );
        if (wroteCompany) {
            // The hidden `companyid` field and its pairing tag, through the one
            // path a real selection uses - NOT conditional on there being an
            // identification field, because this half of the write is what makes
            // the mirrored selection visible to clearStaleOrganizationSelection()
            // and it is needed on every country. See
            // markOrganizationFieldSelected().
            this.markOrganizationFieldSelected(selection.company, selection.companyid);
        }
        // Whether the number actually LANDED, taken from the writer rather than
        // assumed from having called it. The write declines for several reasons: the
        // value being an internal (`TWO:`) identifier, which never enters the visible
        // `dni` field at all; the address-lookup switch being off; or every candidate
        // field skipped by `onlyIfEmpty`. Recording a number the form does not hold
        // would have the next render read the empty field as buyer tampering and pin
        // the whole address on the strength of a write that never happened.
        let wroteNumber = false;
        if (wroteCompany && identifierFields.length > 0) {
            // Through the single gate every other organisation-number write goes
            // through, given a root so it stays inside this block. onlyIfEmpty is
            // false because writability was decided above, by the marker rule -
            // which, unlike "only if empty", lets a NEW company replace the
            // previous mirror's untouched number.
            wroteNumber = this.writeOrganizationToAddressIdentifiers(selection.companyid, false, root);
        }
        const countryValue = this.mirrorCountryIntoForm(root, selection.countryIso);
        if (!wroteCompany && !countryValue) {
            return false;
        }

        memory.companyid = selection.companyid;
        memory.company = wroteCompany ? selection.company : '';
        memory.organization = wroteNumber ? selection.companyid : '';
        // A name written onto a form with no identification field leaves the number
        // half owing. Usually there is nowhere for it to go and it stays owing
        // harmlessly - but a mirrored COUNTRY write can rebuild this form into one
        // that does have the field, and then it is owed to a form that can take it.
        //
        // No `TWO:` carve-out here either: with the write gate gone, an internal
        // identifier owes and settles exactly as any other number does.
        // Owed whenever the name landed and the number did NOT, which is now two
        // shapes rather than one:
        //
        //  - the form has NO identification field at all (the original case): there
        //    is nowhere for the number to go, and usually it stays owing harmlessly -
        //    but a mirrored COUNTRY write can rebuild this form into one that does
        //    have the field, and then it is owed to a form that can take it.
        //  - the field EXISTS but the write skipped it, because the value is an
        //    internal (`TWO:`) identifier that never enters `dni` (TWO-40, Option A).
        //
        // The second shape is why this is keyed on `!wroteNumber` and not on
        // `identifierFields.length === 0`. `organizationPending` is the other half of
        // the mirror's memory, and republishMirroredSelection() reads
        // `organization || organizationPending` to restore the hidden `companyid`
        // pair after core's country-change rebuild. Leaving both halves empty for a
        // sole trader would take that restore away entirely.
        memory.organizationPending = (wroteCompany && !wroteNumber)
            ? selection.companyid
            : '';
        memory.countryValue = countryValue;

        // Report what just went into the secondary address, so the pin can still
        // recognise these values as ours after the next page load has taken every
        // marker with it. Partial: only the halves that were actually written.
        const reported = {};
        if (wroteCompany) {
            reported.company = selection.company;
            reported.organization = memory.organization;
        }
        if (countryValue) {
            reported.country = selection.countryIso;
        }
        this.recordMirrorWrites(reported);

        return true;
    }

    /**
     * Place the organisation number the mirror still owes this form, when the
     * form has acquired somewhere to put it.
     *
     * The case, which core produces on its own: whether the form carries an
     * identification field is decided by the COUNTRY, not by the shop.
     * `AddressFormat::getFormat()` appends `dni` to the country's address format
     * when `Country::isNeedDniByCountryId()` is true, and nothing in the stock
     * address formats mentions `dni` otherwise - so on stock data the field is
     * present exactly when the country requires it, and absent everywhere else.
     * The mirror's own country write is therefore a write that can change which
     * fields exist: a form rendered for a country without the field, mirrored to a
     * country with it, comes back from core's rebuild carrying an empty and
     * REQUIRED identification field. The once-per-company populate gate then
     * forbids ever filling it, and the name goes to the order alone.
     *
     * GATED ON THE MARKED NAME, never on the identification field being empty, and
     * that is the whole point of the method existing separately. "The field is
     * empty" is the very test the populate gate exists to refuse, because a field
     * the buyer deliberately cleared and an unanswered one are the same empty
     * string. What this gate asks instead is whether the NAME currently in the form
     * is still, exactly, the one the mirror recorded writing AND still carries the
     * marker saying so - i.e. whether the pair this method is completing is still
     * the mirror's own pair. The buyer-cleared rule keeps its own separate
     * condition: the number must be one the mirror never placed anywhere
     * (`organizationPending`, see mirrorMemory()), and the field it goes into must
     * be empty with NO marker of any kind - so a field the mirror once wrote and
     * the buyer then cleared, which carries a stale marker, is never refilled here.
     *
     * The residual case is narrow and benign by construction: a buyer who clears
     * the number, leaves for a country with no identification field and returns
     * gets it back. The field is only ever absent on countries that do not require
     * it and only ever pending-completed on countries that do, and core rejects an
     * empty required identification number at save - so the cleared state they are
     * "losing" is one they could not have submitted.
     *
     * @param {Element} root the visible address form's scope
     * @returns {boolean} whether the number was written
     */
    completeMirroredOrganizationNumber(root) {
        const memory = this.mirrorMemory();
        const pending = memory.organizationPending ? String(memory.organizationPending) : '';
        if (!pending) {
            return false;
        }

        const recordedName = memory.company ? String(memory.company) : '';
        if (!recordedName) {
            return false;
        }
        const companyField = $(root).find("input[name='company']").first();
        if (companyField.length === 0) {
            return false;
        }
        const name = String(companyField.val() == null ? '' : companyField.val());
        if (name !== recordedName
            || companyField.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR) !== name) {
            return false;
        }

        const identifierFields = this.addressIdentifierFields(root).filter(
            field => field && field.length > 0
        );
        if (identifierFields.length === 0) {
            return false;
        }
        const unwritten = identifierFields.every(
            field => String(field.val() == null ? '' : field.val()) === ''
                && typeof field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR) === 'undefined'
        );
        if (!unwritten) {
            // The buyer's own number is already there, or one this page wrote and
            // they then edited. Either way the field is theirs and the debt is
            // settled - stop owing it rather than retrying on every mount.
            memory.organizationPending = '';
            return false;
        }

        // Same publish path as the populate above, for the same reason: this is
        // the branch that places the number when core's rebuild is what separated
        // it from the name, and a number placed here with no `companyid` behind it
        // is invisible to the stale-selection guard too.
        this.markOrganizationFieldSelected(recordedName, pending);
        // Answer taken from the writer, never assumed - see the same treatment in
        // populateInvoiceAddressFromConfirmedCompany() above.
        const placed = this.writeOrganizationToAddressIdentifiers(pending, false, root);
        memory.organization = placed ? pending : '';
        // A refusal LEAVES THE DEBT OWING rather than settling it, and that is
        // deliberate. The dominant refusal is an internal (`TWO:`) identifier, which
        // never enters `dni` (TWO-40, Option A) - so `organizationPending` is the
        // only surviving record of the number, and republishMirroredSelection() reads
        // it to restore the hidden `companyid` pair after the NEXT rebuild too.
        // Clearing it here would work exactly once and then lose the pair. Retrying
        // is cheap and idempotent: markOrganizationFieldSelected() above is the part
        // that has to run on every mount anyway.
        if (placed) {
            memory.organizationPending = '';
        }
        if (!placed) {
            return false;
        }
        // The number half has only now reached a field, so the record has to say so
        // - otherwise the next page load reads it as a number the buyer typed and
        // pins the whole address on the strength of the mirror's own write.
        this.recordMirrorWrites({ organization: pending });

        return true;
    }

    /**
     * The confirmed selection the mirror may act on, or null.
     *
     * Through the injected getter, so it comes from the page-lifetime holder
     * (seeded from the server's cart-scoped record on this load) with every check
     * that holder's consumers already apply, rather than from this instance -
     * which is younger than the navigation the mirror exists to cross.
     *
     * Requires the company/organisation-number PAIR, exactly as
     * TwoCheckoutManager.setConfirmedCompanySelection() and
     * TwoOrderIntent.getConfirmedCompanySelection() do. Every other guard on this
     * selection insists on both halves, and a weaker one here is precisely the
     * divergence that would let a company name travel into the address form with
     * no number beside it.
     *
     * @returns {?{company: string, companyid: string, countryIso: string}}
     */
    confirmedCompanyForMirror() {
        const getter = this.config.getConfirmedCompany;
        if (typeof getter !== 'function') {
            return null;
        }
        let selection = null;
        try {
            selection = getter();
        } catch (e) {
            return null;
        }
        if (!selection) {
            return null;
        }
        const company = selection.company ? String(selection.company).trim() : '';
        const companyid = selection.companyid ? String(selection.companyid).trim() : '';
        if (!company || !companyid) {
            return null;
        }
        return {
            company: company,
            companyid: companyid,
            countryIso: selection.countryIso ? String(selection.countryIso).toUpperCase() : ''
        };
    }

    /**
     * Whether a mirrored write into this field would overwrite the buyer's own
     * answer.
     *
     * Writable when the field is UNANSWERED, or when its current value is still
     * exactly what a previous fill recorded writing there. Anything else is the
     * buyer's own answer and is left alone - overwriting a company name the buyer
     * typed by hand would be the same class of bug as a company picked in one
     * place rewriting an address the buyer is not looking at.
     *
     * "Unanswered" is empty for a text input, and for a `<select>` it ALSO
     * includes "still exactly the value the server rendered" - see
     * serverRenderedSelectValue() for why an empty country select does not exist
     * on a real PrestaShop form.
     *
     * Comparisons trim and fold case, on Doug's ruling: a buyer who retyped the same
     * answer in a different case, or left a trailing space, has not authored a
     * different answer, and the value is still the plugin's to replace.
     *
     * @param {Object} field jQuery object, possibly empty
     * @param {string|Array<string>} [unansweredValues] value or values that also
     *        count as unanswered for this field - typically what the last-written
     *        record holds for it, from mirrorWriteAcceptedValues()
     * @returns {boolean}
     */
    mirrorTargetIsWritable(field, unansweredValues) {
        if (!field || field.length === 0) {
            return false;
        }
        const current = this.normalizeMirroredValue(field.val());
        const written = field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR);
        if (typeof written !== 'undefined' && this.normalizeMirroredValue(written) === current) {
            return true;
        }
        if (current === '') {
            return true;
        }
        const accepted = Array.isArray(unansweredValues)
            ? unansweredValues
            : (typeof unansweredValues === 'string' ? [unansweredValues] : []);

        return accepted.some(
            value => value !== '' && this.normalizeMirroredValue(value) === current
        );
    }

    /**
     * Write one mirrored value, respecting the autofill marker.
     *
     * Marks what it writes, same attribute and same meaning as every other write
     * in this class, so a later pass can recognise it in turn.
     *
     * @param {Object} field jQuery object, possibly empty
     * @param {string} value
     * @param {string|Array<string>} [unansweredValues] forwarded to
     *        mirrorTargetIsWritable()
     * @returns {boolean} whether the value was written
     */
    writeMirroredValue(field, value, unansweredValues) {
        const incoming = String(value == null ? '' : value).trim();
        if (!incoming) {
            return false;
        }
        if (!this.mirrorTargetIsWritable(field, unansweredValues)) {
            return false;
        }
        const current = String(field.val() == null ? '' : field.val());
        field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR, incoming);
        if (current !== incoming) {
            field.val(incoming);
            field.trigger('input');
            field.trigger('change');
        }
        return true;
    }

    /**
     * The value the SERVER rendered as selected in a select, or '' when it
     * rendered no real option as selected.
     *
     * This is what "the buyer has not answered the country question" has to mean
     * on PrestaShop, and the reason the mirror cannot test a country select for
     * emptiness. Verified against core: `_partials/form-fields.tpl` emits a
     * disabled, empty-valued placeholder option that is ALWAYS `selected`, and
     * then marks the option matching the field's value `selected` as well - and
     * `CustomerAddressFormatter` sets that value unconditionally, to the
     * address's own country id. Two selected options, last one wins, so
     * `select.value` on a fresh unanswered address form is the rendered country
     * id and never `''`. An emptiness test therefore never fires: the mirror
     * would see a non-empty unmarked value, read it as the buyer's answer, and
     * refuse - every time, on every real form.
     *
     * Read from the `selected` ATTRIBUTE rather than snapshotted at mount,
     * deliberately: the attribute is what the server said, and it survives any
     * later programmatic change to the select's value. A snapshot cannot tell a
     * buyer's in-page change from a value some other code set before the mirror
     * ran.
     *
     * @param {HTMLSelectElement} select
     * @returns {string}
     */
    serverRenderedSelectValue(select) {
        if (!select || typeof select.querySelectorAll !== 'function') {
            return '';
        }
        // LAST wins, as the HTML parser resolves it.
        const marked = select.querySelectorAll('option[selected]');
        let rendered = '';
        for (let index = 0; index < marked.length; index++) {
            if (marked[index].value) {
                rendered = marked[index].value;
            }
        }
        return rendered;
    }

    /**
     * Mirror the country into the visible form's country select.
     *
     * Unanswered means "still the country the server rendered", not "empty" -
     * see serverRenderedSelectValue(). Once the buyer has changed it, the marker
     * rule applies exactly as it does to a text field: a value that is not ours
     * and untouched is the buyer's answer, and is left.
     *
     * @param {Element} root the visible address form's scope
     * @param {string} iso
     * @returns {string} the option value written, or '' when nothing was written
     */
    mirrorCountryIntoForm(root, iso) {
        const target = String(iso == null ? '' : iso).trim().toUpperCase();
        if (!/^[A-Z]{2}$/.test(target)) {
            return '';
        }
        const select = $(root).find("select[name='id_country'], select[name='country']").first();
        if (select.length === 0) {
            return '';
        }
        const optionValue = this.countryOptionValueForIso(select[0], target);
        if (optionValue === null) {
            return '';
        }
        // The record wins over the server's render when there is one, for the reason
        // mirroredAddressFieldStates() gives: core re-renders the form on every
        // country change, so after the first one the server-rendered country IS the
        // buyer's own choice and accepting it would make the country unpinnable.
        const accepted = this.mirrorWriteAcceptedValues(
            'country',
            this.serverRenderedSelectValue(select[0]),
            iso => this.countryOptionValueForIso(select[0], String(iso).toUpperCase())
        );
        return this.writeMirroredValue(select, optionValue, accepted) ? optionValue : '';
    }

    /**
     * The value of the option in a country select standing for an ISO code, or
     * null when the select has no such option.
     *
     * Same three resolution strategies the country READ side uses, in the same
     * order, because they have to agree: the option's own ISO attribute (all
     * three spellings themes use), then the server-built id-to-ISO map, then the
     * option's visible text. The placeholder option is skipped - it has no value
     * and stands for no country.
     *
     * @param {HTMLSelectElement} select
     * @param {string} iso uppercase alpha-2
     * @returns {?string}
     */
    countryOptionValueForIso(select, iso) {
        const options = select.options || [];
        for (let index = 0; index < options.length; index++) {
            const option = options[index];
            if (!option.value) {
                continue;
            }
            const attrIso = option.getAttribute('data-iso-code')
                || option.getAttribute('data-iso')
                || option.getAttribute('data-country-iso');
            if (attrIso && String(attrIso).toUpperCase() === iso) {
                return option.value;
            }
            const mapped = (window.twopayment && window.twopayment.countries)
                ? window.twopayment.countries[option.value]
                : null;
            if (mapped && String(mapped).toUpperCase() === iso) {
                return option.value;
            }
            if (this.extractCountryFromText(String(option.textContent || '')) === iso) {
                return option.value;
            }
        }
        return null;
    }

    /**
     * Setup jQuery UI Autocomplete for company search
     */
    setupAutocomplete() {
        if (!this.config.checkoutHost) {

            return;
        }

        // A destroyed instance must never touch the DOM again.
        //
        // TwoCheckoutManager.handleAddressFormUpdate() destroys this instance and
        // builds a fresh one on every `updatedAddressForm`, but the handler this
        // instance registered on that same event cannot be unregistered -
        // `prestashop.on` has no `off` - so a destroyed instance still gets
        // called and still runs this method.
        //
        // That used to be harmless: the destroyed instance held a jQuery object
        // for the node PrestaShop had already replaced, so its work landed on a
        // detached element and went nowhere. Once this method started
        // re-resolving the field against the live DOM, the destroyed instance
        // began resolving to the SAME live input as the live instance and
        // re-binding the widget with its own stale closures - and its
        // organizationField is still the detached hidden `companyid` input its
        // own init() created, so the selected company's organisation number was
        // written somewhere that no longer submits. Guard, do not re-resolve.
        if (this._destroyed) {
            return;
        }

        // Re-resolve the field before touching it. This method is re-invoked from
        // the `updatedAddressForm` handler, and PrestaShop REPLACES the address
        // form's DOM on that event - so the object cached by init() can be a
        // detached input, and every class and binding below would land on a node
        // that is no longer on the page.
        const currentField = $(this.config.companyFieldSelector);
        if (currentField.length) {
            const previousField = this.companyField;
            const isDifferentNode = previousField
                && previousField.length
                && previousField.get(0) !== currentField.get(0);
            // Release the widget still attached to the node we are moving off.
            // destroy() only ever sees whatever companyField points at now, so
            // without this each address-form update abandoned a live widget - and
            // its `<ul class="ui-autocomplete">` menu, which jQuery UI appends to
            // document.body rather than next to the input - with nothing left
            // holding a reference that could clean either up.
            if (isDifferentNode) {
                // Release the OUTGOING panel - widget included - before we
                // stop pointing at it. This used to call
                // `previousField.autocomplete('destroy')`, which was correct
                // while the widget lived on the company-name field and is
                // dead code now that it lives on the panel's query field:
                // `previousField` can never carry `ui-autocomplete-input`, so
                // the guard simply never fires and every re-render abandoned a
                // live widget. removeDropdown() destroys the widget on the
                // query field it can actually see, and is safe to call before
                // `this.companyField` is reassigned - its own fallback sweep
                // is scoped to the wrapper around the node being abandoned,
                // which is exactly the subtree going away.
                //
                // The fallback engine is unbound separately, just below, and
                // works from its own stored `inputEl` rather than from
                // `_queryField`, so it is unaffected by the nulling here.
                this.removeDropdown();
                previousField.removeClass(
                    'two-company-search-input two-company-search-loading ui-autocomplete-loading'
                );
                // Bound directly via jQuery, not through the widget -
                // `autocomplete('destroy')` above only unwinds bindings the
                // widget itself made (review finding, Han: harmless today
                // only because a detached node can never receive a native
                // focus/mousedown event again, an invariant this method does
                // not otherwise rely on and the next DOM-recycling path could
                // break silently).
                previousField.off('.twoCompanyOpen');
                previousField.removeAttr('readonly aria-haspopup aria-expanded');
            }
            this.companyField = currentField;
        }
        if (!this.companyField || !this.companyField.length) {
            return;
        }

        // Drop any custom dropdown left over from a previous setup before
        // choosing a path. A theme that loads jQuery UI late takes the custom
        // path first and this branch second, which would otherwise leave an
        // orphan container listening on the same input as the real widget:
        // duplicate searches and two things fighting over one spinner.
        this.teardownCustomAutocomplete();

        // Same re-run reasoning as the chip/hint below: a fresh field from an
        // address-form re-render has no wrapper of ours yet.
        this.ensureFieldWrapper();
        this.setupWidthRefreshListener();


        // Empty-field hint. Set here rather than only in the address-form
        // override so it survives PrestaShop replacing the input on
        // `updatedAddressForm`, and so it reaches themes that supply their own
        // address form and never run that override. Before the path branch, so
        // both render paths get it.
        this.applyEmptyFieldHint();

        // The anchored panel and its query field (TWO-25326 §1). Same re-run
        // reasoning as the hint above: this method is the one that runs
        // against whatever field PrestaShop just put on the page, so the panel
        // has to be rebuilt on the same schedule or it goes missing the moment
        // the address form's DOM is replaced.
        this.buildDropdown();

        // Order matters. `setCompanyFieldSearchMode(true)` makes the
        // company-name field readonly, and the ONLY route back to an editable
        // field from there is the panel. So it must not be applied until the
        // panel is known to exist: if buildDropdown() bailed (no wrapper to
        // adopt, or an adopted panel missing its query input), a readonly
        // field with no panel is a dead checkout - the buyer can neither type
        // a company nor search for one. Fail back to a plain editable input,
        // which is the pre-TWO-25288 behaviour and still submits.
        if (!this._queryField || !this._queryField.length) {
            this.setCompanyFieldSearchMode(false);
            // Unbind the openers too, not just the readonly attribute. On a
            // RE-ENTRY (a country change over a field that already carries
            // them) the `mousedown.twoCompanyOpen` handler would otherwise
            // survive and keep calling preventDefault() on a field that is now
            // editable and has no panel to open - so the buyer cannot even
            // place a caret in it.
            this.companyField.off('.twoCompanyOpen');
            // A manual-entry buyer must keep their way back, even here: the
            // tail of this method is unreachable from this return.
            if (this._manualEntry) {
                this.renderBackToSearchLink();
            }
            return;
        }

        // Marker class, applied only once the panel is known to exist.
        this.companyField.addClass('two-company-search-input');

        this.setCompanyFieldSearchMode(!this._manualEntry);
        this.setupCompanyFieldOpeners();
        this.syncNotListedVisibility();
        this.syncSoleTraderEntryVisibility();
        this.syncRegisteredEntryVisibility();

        // Use jQuery UI autocomplete if available; otherwise fallback to custom.
        // `$.fn.autocomplete` alone is not proof of jQuery UI - the older
        // bassistance jquery.autocomplete plugin claims the same name with an
        // incompatible signature, and feeding it this options object would leave
        // the field with no working search at all while skipping the fallback.
        //
        // TWO-25326 §1: the widget is bound to the PANEL'S QUERY FIELD, not to
        // `input[name='company']` as it was through PR #131. That single change
        // is what turns an in-field autocomplete into a real dropdown control:
        // the company-name field stops being the search box, so it can be left
        // untouched until a result is picked, and every keystroke, the 300ms
        // debounce, the loading class the spinner is painted from, and the
        // cursor-key navigation all belong to a control that lives inside the
        // panel. `appendTo` keeps the widget's own `<ul>` inside the panel too,
        // which is what stops it being appended to `<body>` and breaking Tab
        // (the WC §1 defect recorded on this ticket).
        if ($.ui && $.ui.autocomplete && typeof $.fn.autocomplete === 'function') {
            this._queryField.autocomplete({
                appendTo: this._resultsList,
                source: (request, response) => {
                    // TWO-30.x.10 element 1: jQuery UI's own `_resizeMenu`
                    // sizes the dropdown to whichever is WIDER, the field or
                    // the longest rendered label - with up to 50 results plus
                    // the manual-entry row, that reliably outgrows the field
                    // by a large margin (625px against a 281px field, live).
                    // Refreshed on every keystroke, before jQuery UI has a
                    // chance to (re)compute its own inline width, so the CSS
                    // rule below - `max-width: var(...)` - is already correct
                    // by the time this request's response paints. A stylesheet
                    // max-width caps the USED width regardless of what inline
                    // `width` jQuery UI sets, with no `!important` required:
                    // that is simply how the CSS width algorithm resolves
                    // width against max-width.
                    //
                    // ensureFieldWrapper() refreshed alongside it (round-2
                    // review finding, Vader): a field hidden behind a
                    // collapsed checkout step at page load measures 0 width,
                    // so the wrapper's pinned width is cleared rather than
                    // set - and no `resize`/`orientationchange` fires just
                    // because a later step reveals it. The first keystroke
                    // once the buyer reaches it is the next point either
                    // measurement can be trusted, so both are refreshed here,
                    // not only the dropdown clamp.
                    this.ensureFieldWrapper();
                    this.constrainAutocompleteMenuWidth();
                    const term = String(request.term || '');
                    // Manual entry: the buyer has said their company is not in
                    // the register, so no dropdown at all - not results, not a
                    // hint row. The reverse link is the way back.
                    if (this._manualEntry) {
                        response([]);
                        return;
                    }
                    // Sole Trader selected (TWO-40 follow-up): the query field
                    // is not even rendered in this state
                    // (syncQueryFieldSuppression()) so no real keystroke
                    // reaches here, but this is the same
                    // "defence in depth" posture as the manual-entry check
                    // above - a programmatic `input`/`search` trigger must not
                    // run a live search while a sole trader is what's selected.
                    if (this._chipMode === 'sole_trader') {
                        response([]);
                        return;
                    }
                    // Too short to search on - INCLUDING the empty query the
                    // panel opens with. No search is made and no row is
                    // rendered for this any more (TWO-40 follow-up): the
                    // length requirement lives in the query field's own
                    // placeholder (getQueryPlaceholderText()), which - per
                    // TWO-25326 §1's original requirement that the hint be on
                    // screen as soon as the control opens - is already
                    // visible the moment the panel opens, since the field is
                    // still empty then. Trimmed: whitespace is not something
                    // the search can match on.
                    if (term.trim().length < MIN_SEARCH_LENGTH) {
                        // jQuery UI's own __response() never calls _suggest()
                        // for empty content - only _close(), which HIDES the
                        // menu without emptying it. Left alone, a buyer who
                        // had real results on screen and then cleared back
                        // below the threshold would carry those same <li>
                        // elements forward, hidden but still in the DOM,
                        // until the next non-empty response happens to
                        // overwrite them. Cleared explicitly so "too short"
                        // means an empty list, not a hidden stale one.
                        this.clearAutocompleteMenu();
                        response([]);
                        return;
                    }
                    const key = this.buildCacheKey(request.term);
                    const cached = TwoCompanySearch.cacheGet(key);
                    if (cached) {
                        response(this.withNoMatchesRow(cached));
                        return;
                    }
                    this.searchCompanies(request.term, (results, meta) => {
                        if (meta && meta.silent) {
                            // Superseded or aborted. Still has to call response()
                            // so jQuery UI decrements `pending` and clears the
                            // loading class; it drops the content itself because
                            // its requestIndex has already moved on.
                            response([]);
                            return;
                        }
                        if (meta && meta.countryUnresolved) {
                            // Not cached, and not an empty dropdown: an empty
                            // list here would read as "your company is not
                            // registered" when in fact no search was made.
                            response([this.buildSelectCountryItem()]);
                            return;
                        }
                        if (meta && meta.unavailable) {
                            // Not cached: the service may well be healthy again
                            // by the buyer's next keystroke.
                            response([this.buildUnavailableItem()]);
                            return;
                        }
                        // A known-partial list is not worth pinning for 5 minutes.
                        //
                        // The RAW results are cached, never the rendered list:
                        // the zero-results row is decoration owned by this
                        // render, and caching it would put a second copy in the
                        // list on the next cache hit.
                        if (!(meta && meta.degraded)) {
                            TwoCompanySearch.cacheSet(key, results);
                        }
                        response(this.withNoMatchesRow(results));
                    });
                },
                // Deliberately 0, and NOT MIN_SEARCH_LENGTH: jQuery UI never
                // invokes `source` for a term shorter than `minLength`, so
                // leaving the threshold here would swallow those keystrokes
                // silently before `source` ever runs - the widget would revert
                // to the pre-TWO-25288 behaviour of doing nothing at all below
                // the threshold. The threshold has moved into the `source`
                // guard above, which is
                // the ONLY gate on this path and reads MIN_SEARCH_LENGTH; no
                // request can escape it, because `source` is where the request is
                // made.
                minLength: 0,
                // 300ms matches the custom fallback path below and the
                // Magento/WooCommerce plugins.
                delay: 300,
                // #30.x.14 bug 2.1, live-verified: jQuery UI's own default
                // (`my: "left top"` against `at: "left bottom"`) butts the
                // menu directly against the field with a zero-pixel seam - on
                // screen it reads as one continuous control rather than a
                // floating panel distinct from the input, which is exactly
                // the "still just editing in the field" complaint. `top+8`
                // opens a real gap so the two are visibly separate boxes, the
                // same visual break Mag/WC's select2/selectWoo panel has
                // below its own combobox.
                position: { my: 'left top+8', at: 'left bottom', collision: 'none' },
                select: (event, ui) => {
                    // "My company is not on the list" is no longer an item in
                    // this list at all (TWO-25326 §2) - it is a real <button>
                    // outside the scroll container, with its own click
                    // handler. Nothing here has to special-case it.
                    // The "search unavailable" row is a message, not a company:
                    // returning false stops jQuery UI writing it into the field.
                    if (ui && ui.item && ui.item.two_unavailable) {
                        return false;
                    }
                    this.onCompanySelected(event, ui);
                    // A completed selection ends the search. Focus goes back
                    // to the company-name field, which now holds the picked
                    // name (§1: "on selection, the selected name replaces what
                    // was previously in the company-name field").
                    this.closeDropdown(true);
                    // ALWAYS false, never the handler's own result. A truthy
                    // return lets jQuery UI run its own `_value(item.value)`
                    // and `this.term = this._value()` on the QUERY field after
                    // we return - re-seeding the field closeDropdown() has
                    // just cleared. The next open would then search for the
                    // company the buyer has only just confirmed, and offer
                    // them a list containing nothing but it. onCompanySelected()
                    // writes the company-name field itself; the widget must
                    // write nothing.
                    return false;
                },
                focus: (event, ui) => {
                    // ALWAYS false, for every item including real companies.
                    //
                    // The return value gates ONLY jQuery UI's `_value()`
                    // write, which mirrors the key-navigated item's label back
                    // into the input the widget is attached to. That input is
                    // now the QUERY field, and overwriting the buyer's own
                    // search term with "Some Company Ltd (123456789)" the
                    // moment they press Down is both wrong to read and wrong
                    // to search on if they then keep typing. Returning false
                    // does NOT stop the row being highlighted - the menu has
                    // already done that by the time this fires - so cursor-key
                    // navigation and Enter (§1) are untouched.
                    return false;
                }
            });

            // Marker class for the CSS width clamp (TWO-30.x.10 element 1,
            // Han review finding): `.ui-autocomplete` is jQuery UI's own
            // un-namespaced default class, shared by any OTHER jQuery UI
            // autocomplete that might be live on the same page (a native
            // PrestaShop lookup, another module). `addClass` is idempotent, so
            // this is safe to repeat on every setupAutocomplete() re-run.
            //
            // Wrapped in try/catch (round-2 review finding, Han): this is
            // cosmetic, not core search functionality, and
            // `autocomplete('widget')`/`autocomplete('instance')` below it is
            // ALREADY documented as capable of throwing on a non-standard
            // jQuery UI build. An uncaught throw here would escape
            // setupAutocomplete(), init() and the constructor, aborting
            // company search entirely over a failed style hook.
            try {
                const menu = this._queryField.autocomplete('widget');
                menu.addClass(TwoCompanySearch.AUTOCOMPLETE_MENU_CLASS);
                // TWO-25326 §2/§4: jQuery UI's menu widget puts `tabindex="0"`
                // on its own `<ul>`, which makes the RESULTS LIST a tab stop
                // in its own right - so Tab from the query field lands on the
                // list container instead of on "My company is not on the
                // list". That is the identical defect logged against Hyva on
                // this ticket ("the scrollable div that contains the search
                // results is itself a tabstop, which is unwanted"), and it is
                // the widget's default rather than anything this file asked
                // for. The list is navigated with the cursor keys from the
                // query field; it never needs focus of its own.
                menu.attr('tabindex', '-1');
            } catch (e) {
                // Degrade to an unstyled (but still functional) dropdown.
            }

            // Render the message rows as non-selectable. `ui-state-disabled`
            // is what jQuery UI's menu itself checks, so the row is skipped by
            // keyboard navigation rather than merely being refused on select.
            //
            // Company names always go through .text(), matching jQuery UI's own
            // default renderer, so a name containing markup cannot inject HTML
            // into the dropdown.
            //
            // Wrapped: `autocomplete('instance')` only exists from jQuery UI
            // 1.11, and an unknown-method call throws. A theme shipping an older
            // jQuery UI must lose the styling of these rows, not the whole
            // company search - select/focus above already refuse them without it.
            //
            // Patched at most ONCE per widget instance. jQuery UI's widget
            // bridge does not build a fresh instance when `.autocomplete({...})`
            // is called on an already-initialised field - it runs option()+
            // _init() on the existing one - and this method is re-invoked on
            // every country change and address-form update. Without the guard
            // each call would capture the previous wrapper and wrap it again,
            // nesting one layer deeper every time until rendering a row blew the
            // stack.
            try {
                const instance = this._queryField.autocomplete('instance');
                if (instance && typeof instance._renderItem === 'function'
                    && !instance._twoRenderItemPatched) {
                    instance._twoRenderItemPatched = true;
                    const defaultRenderItem = instance._renderItem.bind(instance);
                    instance._renderItem = (ul, item) => {
                        // Normal companies go through jQuery UI's OWN renderer.
                        // Reimplementing it would hard-code one version's markup:
                        // 1.11 emits <li><a>, 1.12 emits <li><div>, and the theme
                        // decides which ships. Overriding both would strip the
                        // anchor from every row on 1.11 and break its highlight.
                        if (!item.two_unavailable) {
                            return defaultRenderItem(ul, item);
                        }
                        // `ui-state-disabled` is what jQuery UI's menu checks, so
                        // the row is skipped by keyboard navigation rather than
                        // merely refused on select. .text() (as jQuery UI's own
                        // renderer does) keeps markup out of the dropdown.
                        // `two_row_class` lets a message row be told apart in the
                        // DOM ("no country chosen" and "unavailable" are not the
                        // same cause, and neither is "No matches found") while
                        // keeping the disabled/keyboard-skip behaviour identical.
                        return $('<li>')
                            .addClass('two-autocomplete-message '
                                + (item.two_row_class || 'two-autocomplete-unavailable') + ' ui-state-disabled')
                            .attr('aria-disabled', 'true')
                            .append($('<div>').text(item.label || ''))
                            .appendTo(ul);
                    };
                }
            } catch (e) {
                // Older jQuery UI without `instance`; styling only, safe to skip.
            }
        } else {
            this.setupCustomAutocomplete();
        }

        // Manual entry survives a COUNTRY CHANGE, which is the one path that
        // re-enters this method on a live instance: its listener re-runs setup
        // against a field it has just cleared, which takes the old link with it,
        // so the link has to be put back or the buyer is stranded in manual mode
        // with no way out.
        //
        // It deliberately does NOT survive an address-form update, despite that
        // path also calling this method. The checkout manager destroys this
        // instance and builds a fresh one on `updatedAddressForm`, the surviving
        // instance's own handler stands down on the `_destroyed` check, and the
        // replacement starts in search mode - so the branch below is unreachable
        // on that path and manual mode resets. That is the intended behaviour, not
        // an oversight: the form has been re-rendered from the server and the
        // buyer is starting that step again.
        //
        // After the path branch, because the fallback path anchors the link below
        // its dropdown container, which only exists once that branch has run.
        if (this._manualEntry) {
            this.renderBackToSearchLink();
        }

        this.restorePanelAfterRerender();
    }

    /**
     * Reopen a panel that a re-render closed on the buyer's behalf.
     *
     * PrestaShop re-renders the address form for ordinary interactions, and in
     * a real browser that re-render can land tens of milliseconds AFTER the
     * click that opened the panel - measured at click +165ms, re-render
     * +195ms. The panel was torn down and rebuilt closed, so the buyer clicked
     * the field, saw a dropdown appear and vanish, and had no way into manual
     * entry at all. Nothing in the unit suite could see it: it needs
     * PrestaShop's own event actually firing after a real click.
     *
     * Called at the very END of setupAutocomplete() on purpose - openDropdown()
     * renders the current search state through the widget, so it cannot run
     * until whichever engine this build uses has been wired up.
     *
     * Deliberately narrow. It restores only what the buyer already had, only
     * while a re-render is plausibly responsible (see _reopenPanelUntil), and
     * never in manual-entry mode, where an open search panel would contradict
     * the mode the buyer chose.
     */
    restorePanelAfterRerender() {
        if (this._destroyed || this._manualEntry) {
            return;
        }
        if (Date.now() >= TwoCompanySearch._reopenPanelUntil) {
            return;
        }
        if (!this._dropdown || !this._dropdown.length
            || !this._queryField || !this._queryField.length) {
            return;
        }
        // Left ARMED, not consumed. One `updatedAddressForm` rebuilds the
        // control twice - this module's own handler rebuilds in place, then
        // the checkout manager destroys the instance and constructs a
        // replacement - and it is the second panel the buyer ends up looking
        // at. Consuming the deadline here would restore the throwaway and
        // leave the real one closed. The deadline expires on its own, and any
        // close clears it.
        const deadline = TwoCompanySearch._reopenPanelUntil;
        this.openDropdown();
        TwoCompanySearch._reopenPanelUntil = deadline;
    }

    /**
     * Render a completed search that matched nothing as an explicit
     * "No matches found" row (TWO-25326 §1).
     *
     * PrestaShop previously showed NOTHING at all here - jQuery UI simply
     * declines to open a menu for an empty item list - which is
     * indistinguishable from a search that never ran, and is the §1 failure
     * recorded on the ticket.
     *
     * Only ever substituted for an EMPTY list; a real result set is passed
     * straight through. The row is not appended alongside results, because
     * "no matches" is false the moment there is one.
     *
     * @param {Array} items
     * @returns {Array}
     */
    withNoMatchesRow(items) {
        const list = items || [];
        return list.length ? list : [this.buildNoMatchesItem()];
    }

    /**
     * @returns {string} EXACT zero-result wording required by TWO-25326 §1.
     *   "No results found" is a different string and does not satisfy it.
     */
    getNoMatchesText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_no_matches)
            || 'No matches found';
    }

    /**
     * Pseudo-result carrying the zero-result message through jQuery UI's
     * result-list plumbing. `two_unavailable` for the same reason
     * buildSelectCountryItem() and buildUnavailableItem() use it: that flag
     * means "not a company", so select / focus / _renderItem keep it out of
     * the field and the keyboard skips it.
     *
     * @returns {Object}
     */
    buildNoMatchesItem() {
        return {
            label: this.getNoMatchesText(),
            value: '',
            two_unavailable: true,
            two_row_class: 'two-autocomplete-no-matches'
        };
    }

    /**
     * @returns {string} label for the "Enter Manually" mode chip (TWO-40
     *   design revision - was a plain-wording link, "My company is not on
     *   the list", before this)
     */
    getManualEntryText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_manual_entry)
            || 'Enter manually';
    }

    /**
     * @returns {string} label for the "Registered Company" mode chip
     *   (TWO-40 design revision) - the default-selected chip, ordinary
     *   company search
     */
    getRegisteredEntryText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_registered_entry)
            || 'Registered company';
    }

    /**
     * @returns {string} label for the "Sole Trader" mode chip (TWO-40)
     */
    getSoleTraderEntryText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_sole_trader_entry)
            || 'Sole trader';
    }

    /**
     * @returns {string} wording for the link back out of manual entry
     */
    getBackToSearchText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_back_to_search)
            || 'Search for company';
    }

    /**
     * Forget the currently selected company, on the browser and on the server.
     *
     * Both halves are required. The hidden organisation field is what the address
     * form submits; the session company is what the order payload and the
     * order-intent handler read FIRST, ahead of the address. Clearing one and not
     * the other leaves the buyer looking at an empty field while the order still
     * carries the old company.
     *
     * THREE halves, in truth. The selection also mirrors the organisation number
     * into the address form's identification-number input, and the server reads
     * that off the saved address on a path of its own,
     * independently of the session company. Worse, the pre-submit sync adopts an
     * identification number with no organisation number beside it AS the
     * organisation number - so a `dni` left behind here is re-adopted at submit
     * and re-tagged with the name the buyer has just typed, which is a credit
     * check on one company under the name of another. Clearing two of the three
     * therefore does not merely leak a stale value, it silently undoes itself.
     *
     * Only the values the lookup wrote go, never a buyer-typed one - see
     * clearLookupWrittenAddressIdentifiers() for why that distinction is
     * load-bearing rather than cautious.
     */
    clearSelectedCompany() {
        if (this.organizationField && this.organizationField.length) {
            this.organizationField.val('');
            this.organizationField.removeAttr('data-two-company-name');
        }
        // The visible label goes with the value behind it (TWO-25326 §5:
        // "manual-entry mode shows NO company-number field/label at all").
        // clearStaleOrganizationSelection() already pairs these two on every
        // branch; this method dropped the number and left the label showing
        // it, which is the same defect one method over.
        this.setCompanyIdHint('');
        this.clearLookupWrittenAddressIdentifiers();
        this.clearPersistedCompany();
        // FOURTH half (TWO-25326 bug 8): the in-memory copy the order-intent
        // payload is built from. Clearing the cookie and leaving this behind
        // would reintroduce the very defect it exists to fix, inverted - the
        // intent would keep credit-checking a company the buyer has explicitly
        // moved off, and would do it in preference to the cleared cookie.
        this.publishConfirmedSelection('', '');
        // FIFTH half (TWO-40 follow-up): whatever sole-trader identity this
        // clear is walking away from, the "Select a different sole trader"
        // link is the inverse of a POPULATED identity and must go with it -
        // same reasoning as renderBackToSearchLink()'s companion removal.
        this.removeSelectDifferentSoleTraderLink();
    }

    /**
     * Ask the server to drop the session company.
     *
     * Its own endpoint action rather than a `saveCompany` with empty values:
     * that action refuses an empty company or company id outright and answers
     * "missing company data", so calling it to clear is a silent no-op - which
     * is exactly the failure this method exists to prevent.
     *
     * Fire-and-forget, and failure is tolerated, matching persistCompanyToCookie()
     * next to it.
     *
     * What makes that tolerable is NOT that the resolver would reject the order.
     * It would not: the resolver returns the session company first, with no
     * comparison against the address, so a dropped or still-in-flight clear
     * would on its own yield a WRONG order rather than a rejected one - silently.
     * (An earlier version of this comment claimed the opposite. It was wrong, and
     * the guarantee it appealed to did not exist.)
     *
     * What makes it tolerable is that the same clear happens server-side and
     * unconditionally: Twopayment::hookActionCustomerAddressSave() drops the
     * session organisation number whenever the address saves a different company
     * name with no organisation number beside it, which is what this state looks
     * like from the server. So the outcome does not depend on this request
     * arriving, and there is no ordering guard here for the same reason - unlike
     * the surcharge-line endpoint, where the browser IS the only writer.
     */
    clearPersistedCompany() {
        try {
            if (!window.twopayment || !window.twopayment.order_intent_url || !window.twopayment.ajax_token) {
                return;
            }
            $.ajax({
                url: window.twopayment.order_intent_url,
                method: 'POST',
                data: {
                    ajax: 1,
                    action: 'clearCompany',
                    token: window.twopayment.ajax_token
                },
                timeout: 10000
            });
        } catch (e) {
            // no-op
        }
    }

    /**
     * Switch to manual entry: forget the selected company, close the dropdown,
     * stop searching, and offer the way back.
     */
    enterManualEntryMode() {
        if (this._destroyed) {
            return;
        }
        this._manualEntry = true;

        // Drop the previously selected company BEFORE anything else.
        //
        // "My company is not on the list" is a statement that the selected
        // company is wrong, and the server-side resolver's FIRST priority is the
        // session company - which outranks the address entirely and is discarded
        // only on a country mismatch or an address switch, never on the company
        // name changing. So without this, a buyer who picks a company, chooses
        // this row, types a different name and places the order has the ORIGINAL
        // company credit-checked: the hidden organisation field is cleared by the
        // typing, and the resolver ignores it and answers from the session.
        //
        // Scoped deliberately to this path. The same hole is reachable today by
        // retyping over a selection, and closing it generally is a separate piece
        // of work - but this element promotes it to a one-click route sitting
        // directly under the results the buyer just chose from, so it cannot ship
        // relying on that.
        this.clearSelectedCompany();

        // The panel closes WITHOUT returning focus itself - this method places
        // focus deliberately, a few lines down, and §2 requires it to land in
        // the manual company-name field. Letting closeDropdown() also focus
        // that field would work by accident today and break the moment the
        // close path changes.
        this.closeDropdown(false);
        this.syncNotListedVisibility();
        this.syncSoleTraderEntryVisibility();
        this.syncRegisteredEntryVisibility();

        // The company-name field stops being a search trigger and becomes the
        // plain text input the buyer types their company into (§2/§5:
        // manual entry captures a name and no number).
        this.setCompanyFieldSearchMode(false);

        this.renderBackToSearchLink();

        // §2: activating "My company is not on the list" places focus in the
        // manual company name field. This is the one place that happens.
        if (this.companyField && this.companyField.length) {
            this.companyField.trigger('focus');
        }
    }

    /**
     * Leave manual entry and re-arm the search for whatever is in the field, so
     * the buyer sees the dropdown again without having to retype.
     */
    exitManualEntryMode() {
        if (this._destroyed) {
            return;
        }
        this._manualEntry = false;
        this.removeBackToSearchLink();

        if (!this.companyField || this.companyField.length === 0) {
            return;
        }

        // Back to being the search trigger, not a text box.
        this.setCompanyFieldSearchMode(true);

        // §3: activating "Search for company" returns to search mode and sets
        // focus to the QUERY field - which is exactly what openDropdown()
        // does, so there is one code path for "the search is now open and
        // focused" rather than two that can drift.
        this.openDropdown();
    }

    /**
     * Render the `Search for company` link below the company field.
     *
     * A real `<button type="button">`: focusable, Enter/Space-activated and
     * announced as a button by the browser with nothing added by hand, which a
     * `<div>` or an `<a>` without `href` is not. `type="button"` because this
     * sits inside PrestaShop's own address form and a default-type button would
     * submit it.
     */
    renderBackToSearchLink() {
        if (!this.companyField || this.companyField.length === 0) {
            return;
        }
        this.removeBackToSearchLink();

        const link = $('<button type="button"></button>')
            .addClass('two-company-search-back')
            .text(this.getBackToSearchText());
        link.on('click.twoManualEntry', (event) => {
            event.preventDefault();
            // Stop the click here (#30.x.14 bug 2.5, live-verified): with no
            // stopPropagation, this click bubbled up into whatever delegated
            // accordion-toggle handler the checkout theme binds on the
            // address step container, and that handler read the same click
            // as "collapse this step" - closing the whole address section the
            // buyer was in the middle of, rather than just switching this
            // field back to search. This button is a plain sibling inside
            // that step's markup, not something the accordion is meant to
            // hear from at all, so the fix is to keep the click here rather
            // than let it carry on up past a node that never asked for it.
            event.stopPropagation();
            this.exitManualEntryMode();
        });

        // Appended to the field wrapper rather than inserted after the input,
        // so it lands BELOW the org-number hint and the (hidden, in manual
        // mode) dropdown panel that share that wrapper - §3 requires it in
        // normal block flow below the company-name field, never overlapping
        // it. Right-alignment is CSS (`.two-company-search-back`), not
        // markup.
        const wrapper = this.companyField.parent();
        if (wrapper.length && wrapper.hasClass('two-company-field-wrap')) {
            wrapper.append(link);
        } else {
            this.companyField.after(link);
        }
        this._backToSearchLink = link;
    }

    /**
     * Remove the reverse link and unbind it.
     *
     * The class-wide sweep is deliberate: this instance's own reference does not
     * cover a link left behind by a previous instance whose field PrestaShop has
     * since replaced, and two of these on one form is worse than none.
     */
    removeBackToSearchLink() {
        if (this._backToSearchLink) {
            this._backToSearchLink.off('.twoManualEntry');
            this._backToSearchLink.remove();
            this._backToSearchLink = null;
        }
        $('.two-company-search-back').off('.twoManualEntry').remove();
    }

    /**
     * @returns {string} caption for renderSelectDifferentSoleTraderLink()
     */
    getSelectDifferentSoleTraderText() {
        return (window.twopayment && window.twopayment.i18n
                && window.twopayment.i18n.company_search_select_different_sole_trader)
            || 'Select a different sole trader';
    }

    /**
     * Relaunch the sole-trader signup/re-selection flow (TWO-40 follow-up).
     * The single shared call for BOTH entry points that mean "pick a
     * different sole trader" - the standalone link/button below the company
     * field, and re-clicking the "Sole Trader" mode chip while a sole
     * trader is already adopted (Doug's ruling: the two must behave
     * identically, not one being a no-op).
     *
     * Re-entrancy guard (adversarial review finding, TWO-40 follow-up -
     * Han/Vader independently caught this): `TwoSoleTrader.startReplacement()`
     * opens the popup SYNCHRONOUSLY with no guard of its own (unlike
     * getCurrentBuyer()'s `isFetchingBuyer`) - without this, a double-click
     * reliably opened two signup popups from one gesture.
     */
    triggerSelectDifferentSoleTrader() {
        if (this._selectDifferentSoleTraderLoading) {
            return;
        }
        this._selectDifferentSoleTraderLoading = true;
        // Released on TwoSoleTrader.js's own settle event - fired from
        // EVERY terminal branch of startReplacement()'s call graph (popup
        // opened, popup blocked, mint failed, or abandoned via a
        // cancelEnrollment() elsewhere) - same event beginSoleTraderLoading()
        // already relies on for the "Sole Trader" chip's fresh-enrolment
        // path, own namespace so the two guards never interfere.
        $(document).off('two:sole-trader-flight-settled.twoSoleTraderReplace' + this._instanceNs)
            .on('two:sole-trader-flight-settled.twoSoleTraderReplace' + this._instanceNs, () => {
                this._selectDifferentSoleTraderLoading = false;
            });
        try {
            if (window.TwoSoleTrader_Instance
                && typeof window.TwoSoleTrader_Instance.startReplacement === 'function') {
                window.TwoSoleTrader_Instance.startReplacement();
            } else {
                // Nothing is going to fire the settle event for this click,
                // so release the guard here rather than leaving it stuck and
                // log visibly rather than a completely silent no-op
                // (adversarial review finding).
                // eslint-disable-next-line no-console
                console.error('Two: TwoSoleTrader_Instance is missing or malformed; cannot reopen the signup popup.');
                this._selectDifferentSoleTraderLoading = false;
                $(document).off('two:sole-trader-flight-settled.twoSoleTraderReplace' + this._instanceNs);
            }
        } catch (e) {
            this._selectDifferentSoleTraderLoading = false;
            $(document).off('two:sole-trader-flight-settled.twoSoleTraderReplace' + this._instanceNs);
        }
    }

    /**
     * Render the "Select a different sole trader" link below the company
     * field (TWO-40 follow-up) - same slot, same styling/gating shape as
     * renderBackToSearchLink() above, but for a COMPLETED sole-trader
     * enrolment rather than manual entry. Called by adoptSoleTraderBuyer()
     * once a named identity has actually landed in the company field.
     *
     * A real `<button type="button">` for the same reason
     * renderBackToSearchLink() is one: focusable, Enter/Space-activated,
     * announced as a button, and `type="button"` so it cannot submit the
     * address form it sits inside.
     */
    renderSelectDifferentSoleTraderLink() {
        if (!this.companyField || this.companyField.length === 0) {
            return;
        }
        this.removeSelectDifferentSoleTraderLink();

        const link = $('<button type="button"></button>')
            .addClass('two-company-select-different-sole-trader')
            .text(this.getSelectDifferentSoleTraderText());
        link.on('click.twoSoleTraderReplace', (event) => {
            event.preventDefault();
            // Same accordion-toggle reason as renderBackToSearchLink()'s own
            // stopPropagation (#30.x.14 bug 2.5): this button is a plain
            // sibling inside the address step's markup, not something the
            // theme's delegated collapse handler is meant to hear from.
            event.stopPropagation();
            this.triggerSelectDifferentSoleTrader();
        });

        // Same placement as renderBackToSearchLink(): appended to the field
        // wrapper so it lands below the org-number hint and the (hidden
        // here) dropdown panel that share that wrapper.
        const wrapper = this.companyField.parent();
        if (wrapper.length && wrapper.hasClass('two-company-field-wrap')) {
            wrapper.append(link);
        } else {
            this.companyField.after(link);
        }
        this._selectDifferentSoleTraderLink = link;
    }

    /**
     * Remove the "Select a different sole trader" link and unbind it. Same
     * class-wide-sweep reasoning as removeBackToSearchLink().
     */
    removeSelectDifferentSoleTraderLink() {
        if (this._selectDifferentSoleTraderLink) {
            this._selectDifferentSoleTraderLink.off('.twoSoleTraderReplace');
            this._selectDifferentSoleTraderLink.remove();
            this._selectDifferentSoleTraderLink = null;
        }
        $('.two-company-select-different-sole-trader').off('.twoSoleTraderReplace').remove();
        // Belt-and-braces: release the re-entrancy guard and its settle
        // listener too, in case this runs while a flight it started is still
        // outstanding (e.g. clearSelectedCompany() firing mid-flight) - a
        // stuck-true guard would otherwise silently no-op every future click
        // on a FUTURE re-rendered link, exactly the failure shape
        // fetchTokens()'s own try/catch elsewhere in this flow exists to
        // avoid.
        this._selectDifferentSoleTraderLoading = false;
        $(document).off('two:sole-trader-flight-settled.twoSoleTraderReplace' + this._instanceNs);
    }

    /**
     * Cache key for a search term.
     *
     * The country half must be exactly what searchCompanies() puts on the wire,
     * or one country's results get served for another's search - and that
     * matters more now the cache outlives the widget instead of dying at the
     * next address-form re-render.
     *
     * That invariant is structural, not a coincidence to be re-checked: both
     * this and searchCompanies() take the value from getCurrentCountry() and
     * neither adds a fallback of its own. A country the key believes in but the
     * request does not is exactly how one country's results end up answering
     * another's search, so do not add one here.
     *
     * getCurrentCountry() CAN now return '' (it stopped guessing - see there),
     * which makes the key `term|`. Nothing is ever filed under it, because
     * searchCompanies() declines to search at all in that case, so the read is
     * a guaranteed miss rather than a bucket where unrelated countries pool.
     * The empty half is still carried rather than special-cased: giving this
     * side its own substitute for "unknown" is the exact mistake above.
     *
     * @param {string} term
     * @returns {string}
     */
    buildCacheKey(term) {
        return term + '|' + this.getCurrentCountry();
    }

    /**
     * Hint shown in the empty company field (TWO-25288).
     *
     * Occupies the placeholder slot, which is why the previous wording there was
     * replaced rather than joined: two hints cannot share one slot, and a
     * separate second row under an empty field would be noise on a field the
     * buyer has not touched yet.
     *
     * Applied only when the field carries no placeholder, so a merchant theme or
     * a shop-level override of the address form still wins. In the standard flow
     * the address-form override has already put the same wording there.
     *
     * @returns {void}
     */
    applyEmptyFieldHint() {
        const field = this.companyField;
        if (!field || field.length === 0) {
            return;
        }
        const existing = field.attr('placeholder');
        if (existing !== undefined && String(existing).trim() !== '') {
            return;
        }
        field.attr('placeholder', TwoCompanySearch.getEmptyFieldHintText());
    }

    /**
     * @returns {string} wording for the empty-field hint
     */
    static getEmptyFieldHintText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_placeholder)
            || 'Enter company name to search';
    }

    /**
     * @returns {string} wording for the query field's placeholder (TWO-40
     *   follow-up).
     *
     * Below MIN_SEARCH_LENGTH, PrestaShop used to show a "Please enter %d or
     * more characters" row inside the dropdown - a second on-screen hint,
     * separate from (and, at first paint, sitting right underneath) the query
     * field's own placeholder, which at the time read the same as the
     * unclicked company field's watermark ("Enter company name to search").
     * Folded into one: the query field's placeholder now carries the length
     * requirement directly, and no separate row is rendered for it any more
     * (see the `source`/fallback-engine call sites this replaced). `%d` is
     * interpolated from MIN_SEARCH_LENGTH, same reasoning as the removed
     * `company_search_too_short` key had - the number the buyer reads must be
     * the number the guard enforces, not a second constant that can drift
     * from it.
     *
     * @returns {string}
     */
    static getQueryPlaceholderText() {
        const template = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_query_placeholder)
            || 'Enter %d or more characters';
        return String(template).replace('%d', String(MIN_SEARCH_LENGTH));
    }

    /**
     * @returns {string} the query field's accessible NAME (adversarial review
     *   finding, round 2) - static, describing the field's role, deliberately
     *   NOT the same string as the placeholder. See the comment in
     *   buildDropdown() where this is applied for why the two must differ.
     */
    static getQueryAriaLabelText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_query_label)
            || 'Search for a company';
    }

    /**
     * Empty the jQuery UI menu's own `<ul>`, in place (TWO-40 follow-up).
     *
     * `response([])` alone leaves stale `<li>`s behind: jQuery UI's
     * `__response()` only calls `_suggest()` (which rebuilds the menu) for
     * NON-empty content - for empty content it calls `_close()` instead,
     * which merely hides the menu without touching its children. Called from
     * the `source` callback's too-short branch, where a previous non-empty
     * response (real results) can otherwise be carried forward hidden rather
     * than cleared.
     *
     * `widget._suggest([])` (adversarial review finding), not
     * `widget.menu.element.empty()`. `_suggest()` is Autocomplete's own
     * method - `menu.element` is a level BELOW that, an internal property of
     * the Menu sub-widget Autocomplete happens to compose, which is not part
     * of Autocomplete's own documented surface at all. `_suggest([])` does
     * the identical `this.menu.element.empty()` internally (see jQuery UI's
     * own source) before rendering zero items, so this gets the same result
     * through the one-level-shallower call. `response([])` right after this
     * still runs `_close()` and hides the (now-empty) menu, so there is no
     * visible flash between the two calls.
     *
     * @returns {void}
     */
    clearAutocompleteMenu() {
        if (!this._queryField || !this._queryField.length || !this._queryField.hasClass('ui-autocomplete-input')) {
            return;
        }
        try {
            const widget = this._queryField.autocomplete('instance');
            if (widget && typeof widget._suggest === 'function') {
                widget._suggest([]);
            }
        } catch (e) {
            // Widget not ready/already torn down; nothing to clear.
        }
    }

    /**
     * Make the panel show the state of whatever the query field currently
     * holds - results, the "type N more characters" hint, or "No matches
     * found" (TWO-25326 §1).
     *
     * Called from openDropdown() so the panel is never blank on open, and
     * from exitManualEntryMode() when the buyer comes back to search.
     *
     * Drives the QUERY field, which is what the widget is bound to. The
     * company-name field is not a search box any more and must not be
     * searched on: doing so is what made the old control re-offer the
     * company the buyer had just confirmed.
     */
    openSearchForCurrentTerm() {
        if (this._destroyed || !this._queryField || !this._queryField.length) {
            return;
        }
        if (this._queryField.hasClass('ui-autocomplete-input')) {
            try {
                this._queryField.autocomplete('search', this._queryField.val() || '');
            } catch (e) {
                // Widget not ready/already torn down; nothing to open.
            }
            return;
        }
        // Custom fallback path: its own `input` listener is the only entry
        // point, and a programmatic `.val()` fires no event.
        this._queryField.get(0).dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Message shown in place of results when the search could not be completed.
     *
     * A failed search must never render as an empty dropdown: to the buyer that
     * is indistinguishable from "your company is not registered", which is a
     * reason to abandon checkout rather than retry.
     *
     * @returns {string}
     */
    getSearchUnavailableText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_unavailable)
            || 'Company search is temporarily unavailable. Please try again.';
    }

    /**
     * Pseudo-result carrying the unavailable message through jQuery UI's
     * result-list plumbing. Flagged so select/focus/render can treat it as a
     * message rather than a company.
     *
     * @returns {Object}
     */
    buildUnavailableItem() {
        return {
            label: this.getSearchUnavailableText(),
            // NOT a safety net, despite appearances: jQuery UI's _normalize()
            // rewrites this as `item.value || item.label`, so by the time the
            // item reaches select/focus/_renderItem its value IS the message
            // text. The `two_unavailable` checks in those three handlers are the
            // only thing keeping it out of the company field - do not remove one
            // on the assumption that an empty value makes it harmless.
            value: '',
            two_unavailable: true
        };
    }

    /**
     * Message shown when the search cannot tell which country to search.
     *
     * Deliberately not the `unavailable` copy: nothing is broken and retrying
     * changes nothing. The one action that helps is the buyer picking a country
     * on the address form, so say that instead of blaming the service.
     *
     * @returns {string}
     */
    getSelectCountryText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_select_country)
            || 'Select your country above to search for your company.';
    }

    /**
     * Pseudo-result carrying the select-a-country message through jQuery UI.
     *
     * Reuses `two_unavailable` rather than adding a second flag: that flag is
     * what the select / focus / _renderItem handlers check to keep a message row
     * out of the company field, and this row needs exactly the same treatment.
     * It means "not a company", not "the service is down".
     *
     * `two_row_class` matches the class the custom fallback path gives this row:
     * nothing is broken here, so it must not be identified in the DOM as the
     * failure row - that conflation is what TWO-25288 removes.
     *
     * @returns {Object}
     */
    buildSelectCountryItem() {
        return {
            label: this.getSelectCountryText(),
            value: '',
            two_unavailable: true,
            two_row_class: 'two-autocomplete-select-country'
        };
    }

    /**
     * Search engine for a theme that ships no jQuery UI (TWO-25326).
     *
     * ONLY the engine. The panel, the query field, the scrollable results host
     * and the "not on the list" button are built by buildDropdown() and are
     * identical on both paths - this method just supplies the debounce, the
     * request and the row rendering that jQuery UI's widget would otherwise
     * supply. That is the whole point of the rework: the previous code had two
     * complete and divergent dropdown implementations, and every §1/§2 defect
     * on this ticket had to be fixed (or was missed) twice.
     */
    setupCustomAutocomplete() {
        if (!this._queryField || !this._queryField.length || !this._resultsList || !this._resultsList.length) {
            return;
        }
        const inputEl = this._queryField.get(0);
        const list = this._resultsList.get(0);

        // Re-entrant: this runs again on every country change and
        // address-form update, and without tearing the previous one down each
        // call left a listener behind that still fired - duplicate searches
        // and duplicate spinner toggles on one shared field.
        this.teardownCustomAutocomplete();

        // Nothing paints the loading class for us on this path.
        const setLoadingState = (isLoading) => {
            this._queryField.toggleClass('two-company-search-loading', !!isLoading);
        };

        /**
         * Render a set of rows into the shared results host.
         *
         * `<ul>`/`<li>` with listbox roles, matching what jQuery UI emits on
         * the other path, so one stylesheet and one set of keyboard rules
         * cover both.
         */
        const renderRows = (rows) => {
            // A repaint invalidates whatever was highlighted; the row at that
            // index is a different company now, or gone.
            nav.index = -1;
            list.innerHTML = '';
            const ul = document.createElement('ul');
            ul.className = 'ui-autocomplete ' + TwoCompanySearch.AUTOCOMPLETE_MENU_CLASS;
            ul.setAttribute('role', 'listbox');
            rows.forEach((row) => {
                const li = document.createElement('li');
                if (row.message) {
                    li.className = 'two-autocomplete-message '
                        + (row.two_row_class || 'two-autocomplete-unavailable') + ' ui-state-disabled';
                    li.setAttribute('aria-disabled', 'true');
                } else {
                    li.className = 'two-autocomplete-item';
                    li.setAttribute('role', 'option');
                    // One activation path for pointer and keyboard alike, so
                    // the two cannot drift. `two:select` is dispatched by the
                    // Enter branch of onQueryKeydown(), defined below.
                    const activate = () => {
                        this.onCompanySelected(null, {
                            item: {
                                value: row.value,
                                lookup_id: row.lookup_id,
                                organization_number: row.organization_number
                            }
                        });
                        this.closeDropdown(true);
                    };
                    li.addEventListener('two:select', activate);
                    li.addEventListener('mousedown', (e) => {
                        // Keeps focus in the query field through the click, so
                        // the panel's focusout close does not fire underneath
                        // the selection it is about to make.
                        e.preventDefault();
                        activate();
                    });
                }
                const inner = document.createElement('div');
                // textContent, never innerHTML: company names come from a
                // third-party register.
                inner.textContent = row.label || row.value || '';
                li.appendChild(inner);
                ul.appendChild(li);
            });
            list.appendChild(ul);
        };

        const messageRow = (item) => ({
            label: item.label,
            message: true,
            two_row_class: item.two_row_class
        });

        /**
         * Cursor-key navigation over the fallback's own rows (TWO-25326 §1).
         *
         * The jQuery UI path gets this from the menu widget. This path had
         * `mousedown` handlers and nothing else, so a keyboard buyer could
         * open the panel, type a query, see results, and have no way at all to
         * choose one. Message rows are skipped, matching `ui-state-disabled`
         * on the other path.
         *
         * Bound to the QUERY FIELD, not to the document: §4 requires key
         * handling to be tied to individual controls so ordinary navigation
         * around the page is untouched.
         */
        const nav = { index: -1 };
        const rows = () => Array.prototype.slice.call(
            list.querySelectorAll('li.two-autocomplete-item')
        );
        const paintActive = (all) => {
            all.forEach((row, i) => {
                if (i === nav.index) {
                    row.classList.add('two-autocomplete-item--active');
                    row.setAttribute('aria-selected', 'true');
                } else {
                    row.classList.remove('two-autocomplete-item--active');
                    row.removeAttribute('aria-selected');
                }
            });
            const active = all[nav.index];
            // The jQuery UI path gets this from the menu widget; without it
            // the two paths diverge on exactly the accessibility contract §4
            // is about.
            if (active) {
                if (!active.id) {
                    active.id = 'two-company-row-' + this._instanceNs + '-' + nav.index;
                }
                this._queryField.attr('aria-activedescendant', active.id);
            } else {
                this._queryField.removeAttr('aria-activedescendant');
            }
            if (active && typeof active.scrollIntoView === 'function') {
                // Keeps a key-navigated row inside the scroll container, the
                // way the widget's menu does on the other path.
                active.scrollIntoView({ block: 'nearest' });
            }
        };
        const onQueryKeydown = (event) => {
            const all = rows();
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (!all.length) {
                    return;
                }
                event.preventDefault();
                if (nav.index === -1) {
                    // From "nothing highlighted", Down goes to the first row
                    // and Up to the LAST. Falling through to the modulo below
                    // would send Up to `(-1 - 1 + n) % n` = the second-to-last
                    // row, silently skipping one.
                    nav.index = event.key === 'ArrowDown' ? 0 : all.length - 1;
                } else {
                    const step = event.key === 'ArrowDown' ? 1 : -1;
                    nav.index = (nav.index + step + all.length) % all.length;
                }
                paintActive(all);
                return;
            }
            if (event.key === 'Enter') {
                const active = all[nav.index];
                if (!active) {
                    return;
                }
                event.preventDefault();
                active.dispatchEvent(new Event('two:select'));
            }
        };
        this._queryField.off('keydown.twoFallback').on('keydown.twoFallback', onQueryKeydown);

        const debounce = { id: null };
        const onInput = () => {
            // Defence in depth: teardown unbinds this listener, but a
            // destroyed instance must not search even if an unbind was missed.
            if (this._destroyed) {
                return;
            }
            const term = inputEl.value || '';
            clearTimeout(debounce.id);
            if (this._manualEntry) {
                debounce.id = null;
                return;
            }
            // Sole Trader selected (TWO-40 follow-up) - same defence-in-depth
            // reasoning as the jQuery UI `source` callback's own check.
            if (this._chipMode === 'sole_trader') {
                debounce.id = null;
                return;
            }
            // Handled SYNCHRONOUSLY, outside the debounce: there is no
            // request to debounce here anyway, since the whole reason this
            // branch exists is that no search will be made. No row is
            // rendered for it any more (TWO-40 follow-up) - the length
            // requirement lives in the query field's placeholder instead, see
            // getQueryPlaceholderText().
            if (term.trim().length < MIN_SEARCH_LENGTH) {
                debounce.id = null;
                setLoadingState(false);
                renderRows([]);
                return;
            }
            debounce.id = setTimeout(() => {
                if (this._destroyed) {
                    return;
                }
                const key = this.buildCacheKey(term);
                const cached = TwoCompanySearch.cacheGet(key);
                if (cached) {
                    setLoadingState(false);
                    renderRows(this.withNoMatchesRow(cached).map(
                        (r) => (r.two_unavailable ? messageRow(r) : r)
                    ));
                    return;
                }
                setLoadingState(true);
                this.searchCompanies(term, (results, meta) => {
                    if (meta && meta.silent) {
                        // Superseded or aborted. A newer request owns the UI
                        // and has already re-armed the spinner, so leave the
                        // loading state alone.
                        return;
                    }
                    // Term moved on without a newer search superseding it (a
                    // programmatic clear fires no input event), so nothing
                    // else would ever clear the spinner.
                    if ((inputEl.value || '') !== term) {
                        setLoadingState(false);
                        return;
                    }
                    setLoadingState(false);
                    if (meta && meta.countryUnresolved) {
                        renderRows([messageRow(this.buildSelectCountryItem())]);
                        return;
                    }
                    if (meta && meta.unavailable) {
                        renderRows([messageRow(this.buildUnavailableItem())]);
                        return;
                    }
                    if (!(meta && meta.degraded)) {
                        TwoCompanySearch.cacheSet(key, results);
                    }
                    renderRows(this.withNoMatchesRow(results).map(
                        (r) => (r.two_unavailable ? messageRow(r) : r)
                    ));
                });
            }, 300);
        };

        inputEl.addEventListener('input', onInput);

        this._customAutocomplete = { list, inputEl, onInput, debounce };
    }

    /**
     * Unbind the fallback engine.
     *
     * Safe to call when nothing is set up, and idempotent - it is used both to
     * make setupCustomAutocomplete() re-entrant and on destroy(). It does NOT
     * remove the panel: the panel is owned by buildDropdown() and is shared
     * with the jQuery UI path.
     */
    teardownCustomAutocomplete() {
        const existing = this._customAutocomplete;
        if (!existing) {
            return;
        }
        if (existing.debounce) {
            clearTimeout(existing.debounce.id);
            existing.debounce.id = null;
        }
        if (existing.inputEl && existing.onInput) {
            existing.inputEl.removeEventListener('input', existing.onInput);
        }
        if (this._queryField && this._queryField.length) {
            this._queryField.off('keydown.twoFallback');
        }
        // Clear this path's spinner class here, not just in destroy(): this
        // method also runs when setup switches from the fallback path to the
        // jQuery UI one (a theme that loads jQuery UI late), and that branch
        // only ever touches `ui-autocomplete-loading`.
        if (this._queryField && this._queryField.length) {
            this._queryField.removeClass('two-company-search-loading');
        }
        this._customAutocomplete = null;
    }
    
    /**
     * Abort any in-flight company search request. Used both when a new
     * search fires (to stop a stale request racing a fresh one) and on
     * teardown.
     */
    _abortPendingCompanySearch() {
        if (this._companySearchXhr && typeof this._companySearchXhr.abort === 'function') {
            try {
                this._companySearchXhr.abort();
            } catch (e) {
                // no-op
            }
        }
        this._companySearchXhr = null;
    }

    /**
     * Search for companies via Two API.
     *
     * Race-condition fix: successive keystrokes across the debounce boundary
     * (jQuery UI's `delay`, or the custom fallback's setTimeout) can have
     * multiple requests in flight at once. Without cancellation, responses
     * can arrive out of order and whichever resolves LAST wins - even if
     * it's for a stale/shorter search term typed earlier. select2 (used by
     * the Magento/Woo plugins) avoids this natively by aborting the previous
     * in-flight request whenever a new query fires; we replicate that
     * behavior here without introducing a new dependency:
     *   1. Bump a monotonically increasing sequence number BEFORE aborting
     *      the previous request, so if abort() synchronously invokes the
     *      old request's error handler, it already sees a stale sequence
     *      number and discards itself silently (no flicker to empty state).
     *   2. Abort the previous jqXHR so the network request itself is
     *      cancelled, not just ignored.
     *   3. Guard both success and error handlers with the sequence check so
     *      even a response that arrives after abort (or a response that
     *      raced in before the abort took effect) is discarded unless it
     *      matches the CURRENT request.
     */
    searchCompanies(term, responseCallback) {
        // Trimmed: this is the last gate before a request is made, and a term of
        // nothing but whitespace is not searchable however it reached here.
        if (String(term).trim().length < MIN_SEARCH_LENGTH) {
            // Empty/short term cancels any pending search rather than racing it.
            this._companySearchSeq += 1;
            this._abortPendingCompanySearch();
            responseCallback([]);
            return;
        }

        // Country ISO for the search. Whatever getCurrentCountry() resolves
        // goes on the wire, and buildCacheKey() files the response under that
        // same value - which is what stops one country's results answering
        // another country's search.
        const country = this.getCurrentCountry();

        if (!country) {
            // No country, no search. The alternative - send the request without
            // the parameter and let the API pick - is not available: `country`
            // is REQUIRED on GET /companies/v2/company, so an omitted one is a
            // 422 the buyer would read as "search is broken". And the guess this
            // replaces was worse than either, because a GB register searched for
            // a Dutch buyer looks like a working search returning no match.
            //
            // Reported distinctly from `unavailable` because the remedy is the
            // buyer's, not ours: pick a country on the address form. Treated
            // like the short-term branch above for sequencing, so a pending
            // request for the previous country cannot land afterwards and
            // repaint the list.
            this._companySearchSeq += 1;
            this._abortPendingCompanySearch();
            responseCallback([], { countryUnresolved: true });
            return;
        }

        // Build URL with correct API parameters. `limit`/`offset` mirror the
        // Magento and WooCommerce plugins: bound the response to one page so
        // a common name in a large country can't return an unbounded list.
        // Offset is always 0 - there is no load-more/next-page UI here, same
        // as select2's `pagination: { more: false }` on the other two
        // platforms.
        const limit = Number(this.config.companySearchLimit)
            || TwoCompanySearch.DEFAULT_COMPANY_SEARCH_LIMIT;
        const params = new URLSearchParams({ q: term, limit: limit, offset: 0, country: country });
        // Direct Two API call from frontend as required
        const searchUrl = TwoCompanySearch.withTwoClientParams(
            `${this.config.checkoutHost}/companies/v2/company?${params}`
        );

        const seq = (this._companySearchSeq += 1);
        this._abortPendingCompanySearch();

        this._companySearchXhr = $.ajax({
            url: searchUrl,
            method: 'GET',
            crossDomain: true,
            dataType: 'json',
            xhrFields: { withCredentials: false },
            beforeSend: this.buildPublicApiBeforeSend(),
            // 30s, not 10s. The server's own retry envelope is stop_after_delay(10),
            // so a 10s client timeout gave up at the exact instant a successful
            // response would have arrived - the buyer saw a failure for a search
            // that had in fact just succeeded. The ceiling has to sit clear of the
            // server's, not on top of it.
            timeout: 30000,
            success: (data) => {
                if (seq !== this._companySearchSeq) {
                    // Stale response for a superseded request. Reported as
                    // `silent` rather than simply dropped: jQuery UI clears
                    // `ui-autocomplete-loading` only when a request's response
                    // callback runs (it decrements `pending` there), so a
                    // dropped callback leaks the spinner forever. It discards
                    // the CONTENT of a superseded request by its own
                    // requestIndex check, so nothing is rendered.
                    // Deliberately does not null _companySearchXhr - that
                    // handle belongs to the newer request now.
                    responseCallback([], { silent: true });
                    return;
                }
                this._companySearchXhr = null;

                const companies = data.items || [];
                const formattedResults = companies.map(company => {
                    // Prefer various keys for broader country support (GB, etc.)
                    let orgNumber = '';
                    if (company.national_identifier) {
                        const ni = company.national_identifier;
                        orgNumber = ni.id || ni.value || ni.organisationNumber || ni.organizationNumber || ni.registration_number || ni.company_number || '';
                    }
                    // Fallbacks commonly used in GB payloads
                    if (!orgNumber) {
                        orgNumber = company.registration_number || company.company_number || '';
                    }
                    // TWO-25326 §12: `TWO:`-prefixed internal identifiers are
                    // never rendered, and the brackets go with them - the
                    // shared helper owns both halves of that rule so this site
                    // cannot render `Company Name ()`. `organization_number`
                    // below still carries the REAL value: it is what gets
                    // selected, persisted and credit-checked.
                    const displayLabel = window.TwoCompanyNumber.labelFor(company.name, orgNumber);
                    return {
                        label: displayLabel,
                        value: company.name,
                        lookup_id: company.lookup_id,
                        organization_number: orgNumber
                    };
                });

                // The endpoint can answer 200 with an empty or partial body when
                // its upstream registry provider timed out, flagging that with
                // `degraded: true`. An absent field must read as false: every
                // response predating the flag lacks it, so `=== true` rather
                // than a truthiness check.
                //
                // Degraded WITH results still renders the results - partial data
                // beats an error message - but is passed through as degraded so
                // the caller does not cache a known-partial list for five
                // minutes and keep serving it after the provider recovers.
                // Degraded with nothing to show is the case that matters,
                // because an empty list is exactly what the buyer misreads as
                // "my company is not registered".
                const degraded = !!(data && data.degraded === true);
                if (degraded && formattedResults.length === 0) {
                    responseCallback([], { unavailable: true });
                    return;
                }

                responseCallback(formattedResults, degraded ? { degraded: true } : null);
            },
            error: (xhr, status, error) => {
                // A genuine abort is routine - the buyer typed another character,
                // or the address form was re-rendered under us - and must not be
                // reported as a failure; the replacement request drives the UI.
                // Same for a response that arrived after its request was
                // superseded. Both still report back as `silent` rather than being
                // dropped, so jQuery UI's `pending` counter stays balanced and
                // the loading spinner is actually cleared (see success handler).
                if (seq !== this._companySearchSeq || status === 'abort') {
                    responseCallback([], { silent: true });
                    return;
                }
                this._companySearchXhr = null;

                // Everything else is a real failure: timeout, 5xx, network error,
                // unparseable body. It must NOT be reported as an empty result
                // set, which is what this handler used to do and is the whole
                // reason a broken search looked like an unregistered company.
                responseCallback([], { unavailable: true });
            }
        });
    }
    
    /**
     * ISO 3166-1 alpha-2 country the company search should query, or `''` when
     * it cannot be established.
     *
     * The company register searched is decided entirely by this value, so a
     * wrong answer here is worse than no answer: the buyer is shown companies
     * from a register their company is not in, and there is nothing on screen
     * saying which register was used. This function therefore resolves or
     * returns empty. It does not guess.
     *
     * Two guesses used to sit at the bottom of this chain and both are gone:
     *
     * - `navigator.language`. The buyer's browser locale has no relationship to
     *   either the shop's country or the company's. A Dutch company bought for
     *   by someone whose laptop is set to en-US searched the US register.
     * - a literal `'GB'`. Any shop that missed every map above searched GB
     *   companies for every buyer, on every keystroke, silently and forever.
     *
     * What replaces them is an authoritative source the module already ships:
     * `window.twopayment.countries`, the complete `id_country` -> ISO map built
     * server-side from `Country::getCountries()` in twopayment.php and injected
     * via `Media::addJsDef`. It supersedes the ten-entry hardcoded id map that
     * used to be strategy three - PrestaShop's country ids are per-installation
     * table rows, not constants, so hardcoding them was wrong on any shop whose
     * country table had been edited, and wrong SILENTLY because a mismatched id
     * simply fell through to the guesses below. TwoOrderIntent.js already reads
     * this same map.
     *
     * The remaining two strategies are both exact matches rather than guesses:
     * `data-iso-code` is the ISO code itself, and the option-text map only
     * resolves on a full country-name match. Either fails closed.
     *
     * A FOURTH strategy sits after all three, and it is the only one that can
     * resolve anything when there is no country select on the page at all:
     * `window.twopayment.billing_country`, the ISO code of the cart's own
     * billing address, resolved server-side. That case is not an edge - it is
     * the payment step, where PrestaShop shows an address SELECTOR rather than
     * the address FORM (checkout/_partials/steps/addresses.tpl only renders
     * address-form.tpl behind `$show_delivery_address_form`), so
     * `select[name='id_country']` does not exist. Without it the control
     * TWO-25326 §7.1 relocated INTO the payment tile could never resolve a
     * country and declined to search on every keystroke - the search looked
     * simply dead.
     *
     * It is deliberately LAST, not first: a buyer who is mid-edit on the
     * address step has a country selected in the form that is not saved on any
     * address yet, and the register they are typing against has to be that
     * one. It is also not a guess - it is the country of the address the order
     * will be billed to - which is why it is allowed to exist here at all
     * while `navigator.language` and a literal 'GB' are not.
     *
     * @returns {string} uppercase ISO code, or '' when unresolvable
     */
    getCurrentCountry() {
        // Both selectors (TWO-40 follow-up, adversarial review finding): this
        // used to check `id_country` only, while TwoSoleTrader.js's
        // billingCountry() and TwoOrderIntent.js's getCurrentAddressCountryISO()
        // both already fell back to `select[name='country']` too. On a theme
        // that renders the field under that name, this method fell straight
        // through to `window.twopayment.billing_country` (a page-load-time
        // value, never reassigned client-side) while TwoSoleTrader.js resolved
        // the LIVE value off the real select - so the sole-trader chip and the
        // company search could silently disagree on country on exactly the
        // theme shape this ticket's fix targets.
        const countryField = document.querySelector("select[name='id_country'], select[name='country']");
        if (countryField && countryField.selectedOptions.length > 0) {
            const selectedOption = countryField.selectedOptions[0];

            // 1. The ISO code, stated outright. Themes that render the country
            // select from PrestaShop's own template carry it.
            const isoCode = selectedOption.getAttribute('data-iso-code') || selectedOption.getAttribute('data-iso');
            if (isoCode) {
                return isoCode.toUpperCase();
            }

            // 2. The server-built id -> ISO map for THIS shop's country table.
            // Values are lower-cased by twopayment.php; the API wants upper.
            const countryId = selectedOption.value;
            const isoFromConfig = (window.twopayment && window.twopayment.countries)
                ? window.twopayment.countries[countryId]
                : null;
            if (isoFromConfig) {
                return String(isoFromConfig).toUpperCase();
            }

            // 3. The option's visible text, for a theme that renders its own
            // select AND loads the search without the module's JS defs.
            const optionText = selectedOption.textContent.trim();
            const countryFromText = this.extractCountryFromText(optionText);
            if (countryFromText) {
                return countryFromText;
            }
        }

        // 4. The cart's billing-address country, resolved server-side. The
        // only source available on a page with no country select - i.e. the
        // payment step, where the tile-mounted control lives. Shape-checked
        // rather than trusted: anything that is not exactly two letters is
        // treated as absent, so a malformed payload cannot put junk on the
        // wire as a `country` parameter.
        const billingCountry = (window.twopayment && window.twopayment.billing_country)
            ? String(window.twopayment.billing_country).trim().toUpperCase()
            : '';
        if (/^[A-Z]{2}$/.test(billingCountry)) {
            return billingCountry;
        }

        // Unresolvable. searchCompanies() declines to search rather than
        // sending a country it invented - see the countryUnresolved branch
        // there for why omitting the parameter is not an option either.
        return '';
    }

    /**
     * Extract country code from country name text.
     *
     * MIRRORED in TwoSoleTrader.js's `extractCountryFromOptionText()` - two
     * independently-loaded modules with no common utility file between them,
     * so this map is duplicated rather than shared. Keep the two in step by
     * hand; there is no test or build step that would catch one drifting from
     * the other.
     *
     * nl/no/sv entries added (adversarial review finding, TWO-40 follow-up
     * round 2): this shop ships nl/no/sv translations, so a theme with no
     * `data-iso*` attribute and no id in `window.twopayment.countries`,
     * rendered in one of those locales, used to fall through this map
     * silently - reaching only English/Spanish/French country names left the
     * text-match strategy blind for three of the shop's own locales, exactly
     * the failure mode this whole fallback chain exists to close.
     */
    extractCountryFromText(text) {
        const countryMap = {
            'united kingdom': 'GB', 'great britain': 'GB', 'uk': 'GB', 'england': 'GB',
            'verenigd koninkrijk': 'GB', 'storbritannia': 'GB', 'storbritannien': 'GB',
            'spain': 'ES', 'españa': 'ES', 'espagne': 'ES',
            'spanje': 'ES', 'spania': 'ES', 'spanien': 'ES',
            'france': 'FR', 'francia': 'FR', 'frankrijk': 'FR', 'frankrike': 'FR',
            'germany': 'DE', 'deutschland': 'DE', 'alemania': 'DE',
            'duitsland': 'DE', 'tyskland': 'DE',
            'italy': 'IT', 'italia': 'IT', 'italie': 'IT', 'italië': 'IT', 'italien': 'IT',
            'netherlands': 'NL', 'holland': 'NL', 'países bajos': 'NL',
            'nederland': 'NL', 'nederländerna': 'NL',
            'belgium': 'BE', 'bélgica': 'BE', 'belgique': 'BE',
            'belgië': 'BE', 'belgia': 'BE', 'belgien': 'BE',
            'united states': 'US', 'usa': 'US', 'estados unidos': 'US', 'verenigde staten': 'US',
            'canada': 'CA', 'canadá': 'CA',
            'australia': 'AU', 'australië': 'AU', 'australien': 'AU'
        };

        const lowerText = text.toLowerCase().trim();
        return countryMap[lowerText] || null;
    }
    
    /**
     * Handle company selection from autocomplete - SIMPLIFIED APPROACH
     */
    onCompanySelected(event, ui) {
        // A destroyed instance's organizationField is a detached hidden input, so
        // writing a selection through it silently loses the organisation number.
        // Stand down instead.
        if (this._destroyed) {
            return false;
        }
        if (!ui.item) {
            return false;
        }

        // A REAL registered-company selection (TWO-40 follow-up, review
        // finding): unconditionally, before either branch below, whether or
        // not this result carries an organisation number yet. The no-org-
        // number branch already tears this down via clearSelectedCompany(),
        // but the org-number branch writes the company field directly and
        // never calls it - without this, a buyer who completed sole-trader
        // enrolment and THEN picked a real company here kept a stale
        // "Select a different sole trader" link pointing at the OLD tokens.
        // Clicking it would reopen the abandoned signup popup, and its
        // eventual completion silently overwrites the just-picked registered
        // company with the sole-trader identity.
        this.removeSelectDifferentSoleTraderLink();

        // TWO-25326: the buyer has now actually picked a company from the
        // search results - the moment TwoCheckoutManager's tile-mode gate
        // (canAutoTriggerOrderIntent()) is waiting for, as opposed to the
        // tile merely being displayed/mounted/selected as a payment option.
        // Set unconditionally, regardless of whether this result carries an
        // organisation number yet (GB resolves it later via lookup_id) -
        // hasConfirmedSelection() is the wrong test here because it can stay
        // false forever for a name-only company, which would wedge this gate
        // shut for a buyer who really did select something.
        try {
            if (window.TwoCheckoutManager_Instance) {
                window.TwoCheckoutManager_Instance._tileCompanySelected = true;
            }
        } catch (e) {
            // noop
        }

        const triggerOrderIntentRecheck = () => {
            try {
                if (
                    window.TwoCheckoutManager_Instance &&
                    window.TwoCheckoutManager_Instance.isTwoPaymentSelected &&
                    window.TwoCheckoutManager_Instance.isTwoPaymentSelected()
                ) {
                    if (window.TwoCheckoutManager_Instance.orderIntent && window.TwoCheckoutManager_Instance.orderIntent.reset) {
                        window.TwoCheckoutManager_Instance.orderIntent.reset();
                    }
                    if (window.TwoCheckoutManager_Instance.triggerOrderIntentForSelection) {
                        window.TwoCheckoutManager_Instance.triggerOrderIntentForSelection();
                    }
                }
            } catch (e) {
                // noop
            }
        };
        
        // SIMPLE & RELIABLE: Direct field assignment like old tillit.js
        this.companyField.val(ui.item.value);

        // Set organization number immediately if available
        if (ui.item.organization_number) {
            this.markOrganizationFieldSelected(ui.item.value, ui.item.organization_number);

            // Publish BEFORE the cookie write and before the intent trigger:
            // this is the copy the intent check will actually read (bug 8).
            this.publishConfirmedSelection(ui.item.value, ui.item.organization_number);

            // Persist for reliability across steps
            this.persistCompanyToCookie({
                company: ui.item.value,
                companyid: ui.item.organization_number
            });

            // Also sync to the DNI field - gated on the address-lookup
            // toggle inside the writer (TWO-25203). Unconditional overwrite so
            // a re-search replaces the previous company's number.
            this.writeOrganizationToAddressIdentifiers(ui.item.organization_number);
        } else {
            // No org number on this result (e.g. GB, resolved later via
            // fetchCompanyDetails/lookup_id). Adversarial review rounds 4-5
            // (TWO-25326): clearing only organizationField+hint (round 4)
            // still left the DNI/VAT identifier fields holding the PREVIOUS
            // company's number, with their autofill marker intact - so
            // setupAddressIdentifierSync()'s submit-time sync would adopt
            // that leftover DNI value as this NEW company's org number,
            // shipping a mismatched pair to the actual credit-check payload.
            // The session cookie (persistCompanyToCookie) needed the same
            // treatment. clearSelectedCompany() already does all of this
            // atomically - use it instead of a partial hand-rolled clear.
            this.clearSelectedCompany();
        }

        // For some countries (e.g. GB), org number may only be present in company details.
        // Defer order-intent trigger until details lookup completes when org number is missing.
        const shouldDeferIntentTrigger = !!ui.item.lookup_id && !ui.item.organization_number;

        // Optional: Fetch additional details for address auto-fill if lookup_id is available
        if (ui.item.lookup_id) {
            const selectedName = ui.item.value;
            this.fetchCompanyDetails(ui.item.lookup_id)
                .then(details => {
                    this.autoFillAddressIfNeeded(details, selectedName);
                })
                .catch(error => {
                    // Silently fail - address auto-fill is not critical
                })
                .finally(() => {
                    if (shouldDeferIntentTrigger) {
                        triggerOrderIntentRecheck();
                    }
                });
        }

        // Country change has been resolved by a fresh company selection
        try { sessionStorage.removeItem('two_country_changed'); } catch (e) {}

        // If org number is already known from selection, run order intent immediately.
        if (!shouldDeferIntentTrigger) {
            triggerOrderIntentRecheck();
        }

        // §2 gating: a company is now captured, so "My company is not on the
        // list" must be hidden. LAST, deliberately - the org number and its
        // tag are what hasConfirmedSelection() reads, and both are committed
        // by this point on every branch above (immediate org number, or none
        // at all, in which case the deferred GB path re-syncs from
        // autoFillAddressIfNeeded() below).
        this.syncNotListedVisibility();
        this.syncSoleTraderEntryVisibility();
        this.syncRegisteredEntryVisibility();

        return true;
    }

    /**
     * Fetch detailed company information
     */
    fetchCompanyDetails(lookupId) {
        // Direct Two API call from frontend as required
        const detailUrl = TwoCompanySearch.withTwoClientParams(
            `${this.config.checkoutHost}/companies/v2/company/${lookupId}`
        );
        
        return new Promise((resolve, reject) => {
            $.ajax({
                url: detailUrl,
                method: 'GET',
                crossDomain: true,
                dataType: 'json',
                xhrFields: { withCredentials: false },
                beforeSend: this.buildPublicApiBeforeSend(),
                timeout: 10000,
                success: resolve,
                error: (xhr, status, error) => {
                    reject(new Error(`Company details fetch failed: ${error}`));
                }
            });
        });
    }
    
    /**
     * Auto-fill address if needed (simplified version)
     */
    autoFillAddressIfNeeded(details, selectedName) {
        try {
            // Update organization number if we have a more authoritative one
            const natId = (details && (details.national_identifier || details.nationalIdentifier)) ||
                          (details && details.company && (details.company.national_identifier || details.company.nationalIdentifier));
            let natIdVal = natId ? (natId.id || natId.value || natId.organisationNumber || natId.organizationNumber || natId.registration_number || natId.company_number) : null;
            // Additional common fallbacks (GB)
            if (!natIdVal) {
                natIdVal = details.registration_number || details.company_number ||
                           (details.company && (details.company.registration_number || details.company.company_number)) || null;
            }
            // This lookup was fired from the moment of selection and can
            // resolve well after it (a real network round trip) - if the
            // buyer has since typed a different search, the field's CURRENT
            // value is no longer the company this number belongs to. Adopting
            // it anyway would tag someone else's organisation number onto
            // whatever the buyer is now typing, and - since that tag is
            // exactly what hasConfirmedSelection() reads - cover the field
            // they are actively using, and hide the "not on the list" button
            // from under a buyer who has in fact captured nothing. Bail
            // rather than risk either.
            const stillOnSameCompany = selectedName === undefined
                || this.normalizeCompanyName(this.companyField ? this.companyField.val() : '')
                    === this.normalizeCompanyName(selectedName);
            if (natIdVal && stillOnSameCompany) {
                const currentOrgNumber = this.organizationField.val();
                if (!currentOrgNumber || currentOrgNumber !== natIdVal) {
                    this.markOrganizationFieldSelected(
                        this.companyField ? this.companyField.val() : '',
                        natIdVal
                    );
                    this.writeOrganizationToAddressIdentifiers(natIdVal);
                    // Deferred (GB) path: the number only exists now, so this
                    // is where the confirmed pair becomes publishable - and it
                    // must be published BEFORE the intent trigger that
                    // onCompanySelected()'s `finally` fires off the back of this
                    // same lookup (TWO-25326 bug 8).
                    this.publishConfirmedSelection(
                        this.companyField ? this.companyField.val() : '',
                        natIdVal
                    );
                    // Persist to cookie so backend can use it during order placement
                    this.persistCompanyToCookie({
                        company: this.companyField ? this.companyField.val() : '',
                        companyid: natIdVal
                    });
                    // §2 gating reads hasConfirmedSelection(), which only
                    // becomes true once the tag written two lines up exists -
                    // so on the GB path this, not onCompanySelected(), is
                    // where the "not on the list" button finally hides.
                    this.syncNotListedVisibility();
                    this.syncSoleTraderEntryVisibility();
                    this.syncRegisteredEntryVisibility();
                }
            }
            // Find addresses list in various shapes. Gated by the SAME
            // stillOnSameCompany check as the organisation number above - a
            // stale deferred lookup overwriting street/city/postcode with an
            // abandoned company's address is exactly the same hazard, just on
            // different fields, and this response can carry both.
            const addresses = (details && (details.addresses || (details.company && details.company.addresses))) || [];
            if (Array.isArray(addresses) && addresses.length > 0 && stillOnSameCompany) {
                // THREE states here, not two, and the two that look alike are not
                // (TWO-40, round 1 of the content-match rework):
                //
                //  - the form on screen IS the secondary address: the fill's writes
                //    go into the address the pin judges, so they have to be
                //    attributable to a block and reported as ours;
                //  - the invoice form is on screen but the scope resolution FAILED
                //    CLOSED: there is no single-address block to attribute a write
                //    to, and the document-wide branch would write into exactly the
                //    markup visibleAddressFormRoot() has just refused to scope to -
                //    a theme's flattened step, with the other address inside it. So
                //    this fill is SKIPPED. No scope means no write, the same answer
                //    the mirror gives, rather than the widest possible write;
                //  - anywhere else - the shipping pass, the payment tile, a page
                //    with no address form at all - the original document-wide
                //    branch is what runs, unchanged.
                const secondaryRoot = this.secondaryAddressFormRoot();
                const invoiceFormWithNoScope = !secondaryRoot
                    && this.visibleAddressFormType() === 'invoice'
                    && !this.visibleAddressFormRoot();
                if (secondaryRoot) {
                    this.recordMirrorWrites(this.autoFillAddress(addresses, secondaryRoot));
                } else if (!invoiceFormWithNoScope) {
                    this.autoFillAddress(addresses);
                }
            }
        } catch (e) {
            // ignore
        }
    }
    
    
    /**
     * Auto-fill address fields with company address data.
     *
     * Optionally confined to ONE address block (TWO-40). Street, postcode and city
     * were the only writes in this class still made by a document-wide selector,
     * which meant a value in one of them could not be attributed to a block at all -
     * and the secondary address's pin has to attribute every field it judges, or a
     * street the lookup itself wrote reads as one the buyer authored.
     *
     * The document-wide branch is kept EXACTLY as it was and is what every caller
     * that passes no root still takes, the same way the organisation-number writer
     * was handled: narrowing the default silently would change callers that run on
     * pages where these fields are not inside an address block at all.
     *
     * @param {Array<Object>} addresses
     * @param {Element} [root] confine the writes to one address block
     * @returns {Object} what this fill now owns, keyed by field name - the value it
     *          wrote, or '' for a value of its own that it cleared. A field it left
     *          alone is absent.
     */
    autoFillAddress(addresses, root, bypassAddressLookupGate) {
        // Single gate for the address-field writes (TWO-25203). Both call
        // paths into the fill land here.
        //
        // `bypassAddressLookupGate` (TWO-40 follow-up, live bug reported by
        // Doug 2026-08-12): autoFillSoleTraderAddress() passes `true`. This
        // gate's OWN semantics are "did a company-SEARCH selection write into
        // the address step" (PS_TWO_ADDRESS_LOOKUP) - and
        // `Twopayment::getAddressLookupEnabled()` forces it to '0' outright
        // once company search has relocated out of the address area and into
        // the payment tile, which is exactly where TWO-40 put the sole-trader
        // entry point and the ONLY place it now lives. Every shop running the
        // current design therefore has this switch permanently off, so the
        // sole trader's registered address silently never reached the form -
        // no error, nothing to show for it, while the name/number writes
        // beside it in adoptSoleTraderBuyer() are unconditional and worked
        // fine. A signup completion is not a company-search match; the switch
        // has nothing to say about it.
        if (!bypassAddressLookupGate && !this.isAddressLookupEnabled()) {
            return {};
        }

        // Prefer business/registered/visiting; fallback to first
        const address = addresses.find(addr => (addr.type && (
            String(addr.type).toUpperCase().includes('BUSINESS') ||
            String(addr.type).toUpperCase().includes('REGISTERED') ||
            String(addr.type).toUpperCase().includes('VISITING')
        ))) || addresses[0];
        if (!address) return {};
        // Normalize key variants
        const street = address.street_address || address.streetAddress || address.street || address.address_line_1 || address.addressLine1 || '';
        const postal = address.postal_code || address.postalCode || address.zip || address.zip_code || '';
        const city = address.city || address.locality || '';
        // `address2` is written only when the address actually carries a second line.
        // The key is absent on every company-lookup address, which coalesces to '' -
        // and an empty incoming value takes the branch below that clears ONLY a value
        // this class wrote itself, so an unrelated selection can never blank a second
        // line the buyer typed. See autoFillSoleTraderAddress() for what fills it.
        const secondLine = address.address_line_2 || address.addressLine2 || address.address2 || '';
        const fieldMappings = {
            'address1': street,
            'address2': secondLine,
            'postcode': postal,
            'city': city
        };
        const owned = {};
        Object.entries(fieldMappings).forEach(([fieldName, value]) => {
            // The document-wide read is the ORIGINAL and stays byte-for-byte what it
            // was; a root, when one is given, is the only thing that narrows it.
            const field = root
                ? $(root).find(`input[name='${fieldName}']`).first()
                : $(`input[name='${fieldName}']`);
            if (field.length === 0) {
                return;
            }

            const incoming = String(value == null ? '' : value);
            const current = String(field.val() == null ? '' : field.val());
            // What a previous fill wrote here, if it was us. The key variants
            // above all coalesce to '', so an address simply missing a key is
            // indistinguishable from one carrying an empty string by the time we
            // get here - which is why the old undefined/null guard never fired
            // and an absent city blanked a city the buyer had typed.
            const written = field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR);

            if (incoming === '') {
                // Nothing to fill. Clear only what we ourselves put here and
                // the buyer has not since changed - otherwise selecting company
                // B would leave company A's street sitting in the form. Any
                // other value is buyer input and is left alone: a company
                // record missing a field is not evidence the buyer's own answer
                // is wrong.
                if (typeof written !== 'undefined' && written === current && current !== '') {
                    field.removeAttr(TwoCompanySearch.AUTOFILL_MARKER_ATTR);
                    field.val('');
                    field.trigger('input');
                    field.trigger('change');
                    owned[fieldName] = '';
                }
                return;
            }

            // Record the value as ours even when it already matches, so a later
            // fill can still recognise it as autofilled rather than typed.
            field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR, incoming);
            owned[fieldName] = incoming;
            if (current !== incoming) {
                field.val(incoming);
                field.trigger('input');
                field.trigger('change');
            }
        });

        return owned;
    }

    /**
     * Setup event listener for country changes to refresh autocomplete
     */
    setupCountryChangeListener(retryCount = 0) {
        // A destroyed instance must not re-bind anything (see setupAutocomplete).
        if (this._destroyed) {
            return;
        }
        // Try multiple possible selectors for country field
        const possibleSelectors = [
            "select[name='id_country']",
            "select[name='country']", 
            "#id_country",
            ".js-country",
            "select.country"
        ];
        
        let countryField = null;
        for (const selector of possibleSelectors) {
            countryField = document.querySelector(selector);
            if (countryField) {
                
                break;
            }
        }
        
        if (countryField) {
            if (this.countryListener && this._boundCountrySelector) {
                this._boundCountrySelector.removeEventListener('change', this.countryListener);
            }
            this.countryListener = () => {
                try { sessionStorage.setItem('two_country_changed', '1'); } catch (e) {}

                // Close an open panel BEFORE blanking anything below. This
                // runs on the SAME instance (unlike a genuine
                // updatedAddressForm re-render, which destroy()s and replaces
                // it), so a panel left open would keep showing results from
                // the PREVIOUS country's register next to a field this
                // handler is about to clear. Focus is deliberately not
                // returned: the buyer is interacting with the country select,
                // and yanking focus out of it mid-change is worse than
                // leaving it be.
                this.closeDropdown(false);

                // Abandon any sole-trader enrolment in flight for the
                // PREVIOUS country (adversarial review round 2, TWO-40
                // follow-up - Han finding). Same call `openDropdown()`/the
                // "Registered Company" chip handler already make before
                // doing anything else - this listener was the one path that
                // could reach `startReplacement()` (via the "Select a
                // different sole trader" link, which is NOT gated behind an
                // open dropdown the way the "Sole Trader" chip is) without
                // it. Without this, a mint/lookup started for the old
                // country resolves with `_enrollGeneration` never bumped,
                // reads as still-current, and can pop a signup popup - or
                // worse, silently publish a completed enrolment - for a
                // country the buyer has already moved off.
                if (window.TwoSoleTrader_Instance
                    && typeof window.TwoSoleTrader_Instance.cancelEnrollment === 'function') {
                    window.TwoSoleTrader_Instance.cancelEnrollment();
                }

                if (this.companyField && this.companyField.length > 0) {
                    this.companyField.val('');
                }
                // Adversarial review round 5 (TWO-25326): a manual
                // organizationField+hint clear here left the DNI/VAT
                // identifier fields and the session cookie holding the
                // PREVIOUS country's company - same gap as the
                // onCompanySelected() no-org-number branch, same fix.
                this.clearSelectedCompany();
                // Recreate autocomplete to ensure new country is used immediately
                this.setupAutocomplete();
            };
            countryField.addEventListener('change', this.countryListener);
            this._boundCountrySelector = countryField;
        } else {
            
            // Log all select elements to help debugging
            
            
            // Try again after a delay (DOM might not be fully ready) - max 3 retries
            if (retryCount < 3) {
                this._countryRetryTimeoutId = setTimeout(() => {
                    this.setupCountryChangeListener(retryCount + 1);
                }, 1000);
            }
        }
        
        // Also listen for PrestaShop address form updates.
        //
        // Registered at most once per instance. The handler calls back into this
        // very method, which used to register another handler each time - so the
        // count DOUBLED on every `updatedAddressForm`, and none were ever
        // unregistered. Every duplicate then re-ran the whole re-setup, so the
        // work per event grew exponentially. (The country listener above is
        // already de-duplicated by the removeEventListener it does first; only
        // this registration lacked an equivalent, and `prestashop.on` exposes no
        // matching `off` to unregister in destroy().)
        if (!this._addressFormListenerBound && typeof prestashop !== 'undefined' && prestashop.on) {
            this._addressFormListenerBound = true;
            prestashop.on('updatedAddressForm', () => {
                // Bail once destroyed. `prestashop.on` has no `off`, so this
                // handler outlives the instance that registered it and the only
                // available defence is for the callback to stand down. Note what
                // the guard above does and does not buy: it stops ONE instance
                // registering repeatedly, but the manager creates a new instance
                // per address-form update and each one registers once, so the
                // handler count still grows by one per event. They are inert
                // after this check, which is what makes that acceptable.
                if (this._destroyed) {
                    return;
                }
                // Close an open panel BEFORE re-resolving the field below. setupAutocomplete() reassigns
                // `this.companyField` to whatever node is live now, and the
                // panel that belonged to the OLD wrapper goes with the
                // replaced DOM. Close (and drop the references) first, on the
                // SAME instance, so a deferred close cannot fire against a
                // detached panel afterwards - the same disarm
                // setupCountryChangeListener()'s own change handler does, for
                // the identical reason.
                //
                // The close is a mechanical consequence of the re-render, not
                // something the buyer asked for, so remember an open panel and
                // let the rebuilt one restore itself. PrestaShop fires this
                // event for ordinary things - and, as seen in a real browser,
                // it can land tens of milliseconds AFTER the click that opened
                // the panel, so the buyer's open was being silently discarded
                // and the control looked simply dead. See _reopenPanelUntil.
                const wasOpen = this._dropdownOpen;
                this.closeDropdown(false);
                // AFTER the close, which clears the deadline itself so that a
                // close the buyer did ask for cannot be undone by a later
                // rebuild.
                if (wasOpen) {
                    TwoCompanySearch._reopenPanelUntil
                        = Date.now() + TwoCompanySearch._REOPEN_WINDOW_MS;
                }
                // Address form was re-rendered; re-bind country listener and autocomplete
                this.setupCountryChangeListener(0);
                this.setupAutocomplete();
            });
        }
    }

    destroy() {
        try {
            // Cancel any in-flight company search so it can't resolve after teardown.
            this._companySearchSeq += 1;
            this._abortPendingCompanySearch();

            // Remove country change listener
            const countryField = document.querySelector("select[name='id_country']");
            // Stop the pending retry before anything else: it would otherwise
            // fire up to 3s from now, resolve the country select against the
            // LIVE document and bind this dying instance's listener to it.
            clearTimeout(this._countryRetryTimeoutId);
            this._countryRetryTimeoutId = null;
            // Same reason: a deferred panel close firing after teardown would
            // reach into a detached subtree.
            clearTimeout(this._closeTimerId);
            this._closeTimerId = null;
            // Unbind from the element actually bound. setupCountryChangeListener
            // picks the first of five fallback selectors, so re-querying only
            // `select[name='id_country']` here missed the listener entirely on a
            // theme that matched one of the others - leaking a live handler per
            // address-form update.
            if (this.countryListener) {
                if (this._boundCountrySelector) {
                    this._boundCountrySelector.removeEventListener('change', this.countryListener);
                } else if (countryField) {
                    countryField.removeEventListener('change', this.countryListener);
                }
            }
            // Hand the company field back the way we found it. The openers
            // and the readonly/aria attributes are ours, not PrestaShop's,
            // and a fresh instance may reuse this very node.
            if (this.companyField && this.companyField.length) {
                this.companyField.removeClass(
                    'two-company-search-input two-company-search-loading ui-autocomplete-loading'
                );
                this.companyField.off('.twoCompanyOpen');
                this.companyField.removeAttr('readonly aria-haspopup aria-expanded');
            }
            // Destroy the widget - which now lives on the panel's query field,
            // not on the company field.
            if (this._queryField && this._queryField.length && this._queryField.hasClass('ui-autocomplete-input')) {
                this._queryField.autocomplete('destroy');
            }
        } catch (e) {
            // no-op
        }
        // Remove the custom dropdown in its OWN try. It unbinds live listeners and
        // clears the pending debounce, so it must not be skippable by an earlier
        // failure - jQuery UI's bridge can throw on a foreign or half-initialised
        // widget, and leaving those listeners bound while the instance is marked
        // destroyed is exactly the zombie this guard exists to stop.
        try {
            this.teardownCustomAutocomplete();
        } catch (e) {
            // no-op
        }
        // Its own try for the same reason: the reverse link is a live listener on
        // a node that outlives this instance otherwise.
        try {
            this.removeBackToSearchLink();
        } catch (e) {
            // no-op
        }
        // Its own try for the same reason as the reverse link above (TWO-40
        // follow-up): a live listener on a node that outlives this instance
        // otherwise.
        try {
            this.removeSelectDifferentSoleTraderLink();
        } catch (e) {
            // no-op
        }
        // Its own try, same reason (adversarial review round 3, TWO-40
        // follow-up - Vader finding): `TwoCheckoutManager.handleAddressFormUpdate()`
        // destroys and rebuilds this instance on EVERY `updatedAddressForm`
        // firing, not only on a country change - PrestaShop emits that event
        // for far more than country changes (see the comment at its own call
        // site). The country-select listener's own `cancelEnrollment()` call
        // (round 2) only covers the country-change trigger; this covers every
        // OTHER address-form replacement too. Without it, a sole-trader
        // enrolment started against THIS instance, still in flight when the
        // form gets replaced, resolves later against whatever instance is
        // mounted then - `TwoSoleTrader.applyBuyer()` resolves
        // `TwoCheckoutManager_Instance.companySearch` fresh, not a captured
        // reference - silently adopting the identity into an address context
        // the buyer has since moved on from, ungated because no generation
        // bump ever ran for this trigger.
        try {
            if (window.TwoSoleTrader_Instance
                && typeof window.TwoSoleTrader_Instance.cancelEnrollment === 'function') {
                window.TwoSoleTrader_Instance.cancelEnrollment();
            }
        } catch (e) {
            // no-op
        }
        // Its own try for the same reason as the reverse link: the panel
        // carries live click/keydown/focus handlers on nodes that would
        // otherwise outlive this instance.
        try {
            this.removeDropdown();
        } catch (e) {
            // no-op
        }
        // Its own try for the same reason again: a live `window` listener
        // outliving this instance, plus a page-wide CSS variable (TWO-30.x.10
        // review finding) that must not keep clamping some LATER field's
        // dropdown to a width this, now-dead, field last held.
        try {
            // By reference, not by namespace alone (round-2 review finding,
            // Vader) - `window` is a genuine page-wide singleton, so a
            // namespace-only `.off('.twoCompanyWidth')` would remove another
            // still-live instance's handler too, were one ever to exist at
            // the same time as this one's teardown.
            if (this._widthRefreshHandler) {
                $(window).off('resize.twoCompanyWidth orientationchange.twoCompanyWidth', this._widthRefreshHandler);
            }
            clearTimeout(this._widthRefreshTimeoutId);
            this._widthRefreshTimeoutId = null;
            document.documentElement.style.removeProperty('--two-company-search-width');
        } catch (e) {
            // no-op
        }
        this.isInitialized = false;
        // Set LAST and unconditionally, outside the try: even if teardown threw
        // half way, this instance must never act on the DOM again. Everything
        // that can be re-entered from an event checks it.
        this._destroyed = true;
    }

    /**
     * Publish the confirmed company/organisation-number pair to
     * TwoCheckoutManager, which holds it for the page's lifetime and hands it
     * to TwoOrderIntent.collectFormData() (TWO-25326 bug 8).
     *
     * This is what makes the order-intent payload carry the company the buyer
     * actually just picked. It cannot come from the session cookie, because
     * that cookie is written by persistCompanyToCookie()'s fire-and-forget
     * `saveCompany` request and the intent check goes out in the same tick, on
     * a request whose cookie header therefore still holds the PREVIOUS
     * selection: nothing at all on the first search (which the server's own
     * fallbacks quietly repair, so the first cycle looks correct), and company
     * A on the second - the exact "select A, search again, select B, intent
     * fires for A" defect. Nor can it come from the DOM: in tile mode
     * collectFormData() must not trust the address-area fields at all (§7.1),
     * and by the payment step PrestaShop has removed that form anyway.
     *
     * Passing an empty name or number CLEARS the published selection rather
     * than half-writing one - see clearSelectedCompany(), which relies on
     * that.
     *
     * @param {string} company
     * @param {string} companyid
     * @returns {void}
     */
    publishConfirmedSelection(company, companyid) {
        try {
            const manager = window.TwoCheckoutManager_Instance;
            if (!manager || typeof manager.setConfirmedCompanySelection !== 'function') {
                return;
            }
            manager.setConfirmedCompanySelection({ company: company, companyid: companyid });
        } catch (e) {
            // no-op: this is a checkout convenience, never a gate.
        }
    }

    /**
     * Record a confirmed company/organisation-number pair on the BROWSER side of
     * the selection: the hidden `companyid` input the address form submits, the
     * pairing tag that says which company name that number belongs to, and the
     * visible number hint beside the field.
     *
     * The one path for that, deliberately, and the reason it exists as a method
     * rather than three lines repeated: `data-two-company-name` is not decoration.
     * It is the whole input to clearStaleOrganizationSelection(), which is what
     * drops a selection once the buyer retypes the company name over it - and that
     * guard reads `companyid` FIRST and returns immediately when it is empty. So a
     * caller that places an organisation number anywhere else and skips these two
     * writes does not merely miss a hint: it produces a selection the stale-
     * selection guard cannot see at all, which is a credit check on one company
     * under another company's name. That is exactly what the invoice-address
     * mirror did before it was routed through here (TWO-40, round 5).
     *
     * Does NOT publish to the manager and does NOT persist to the session: those
     * are separate concerns with separate ordering requirements, and one caller -
     * the mirror - must not do either. See mirrorConfirmedCompanyToInvoiceAddress()
     * for why re-publishing a RESTORED selection would corrupt it.
     *
     * @param {string} company the confirmed company NAME this number belongs to
     * @param {string} companyid the organisation number
     * @returns {boolean} whether the pair was recorded
     */
    markOrganizationFieldSelected(company, companyid) {
        if (!this.organizationField || this.organizationField.length === 0) {
            return false;
        }
        const number = String(companyid == null ? '' : companyid).trim();
        if (!number) {
            return false;
        }
        this.organizationField.val(number);
        this.organizationField.attr(
            'data-two-company-name',
            String(company == null ? '' : company)
        );
        this.setCompanyIdHint(number);

        return true;
    }

    /**
     * Adopt a COMPLETED sole-trader enrolment into the form the buyer is looking
     * at - company name, organisation number, and the registered address
     * (TWO-40).
     *
     * The whole point of the enrolment flow is that the buyer's registered data
     * lands in the checkout, and until this existed none of it did. The completion
     * posted to `saveCompany`, published an in-memory selection, and wrote nothing
     * to any input at all - reported as: "Sole trader workflow is not actually
     * populating company name or address from the autofill call. Absolutely
     * nothing is being populated."
     *
     * Deliberately composed from the writers a real search selection already uses,
     * rather than a second set of its own. Three previous attempts at this
     * write-back were withdrawn (`.ai/decisions.md`, 2026-08-10) and every one
     * failed the same way: a hand-rolled write the rest of this class did not
     * recognise as its own. So the number goes in through
     * markOrganizationFieldSelected(), which sets the hidden `companyid` AND its
     * `data-two-company-name` pairing tag together. **The tag is what makes this
     * survive.** An untagged non-empty `companyid` is read by
     * clearStaleOrganizationSelection() as company-set / number-set / tag-absent -
     * "the buyer has edited past a stale selection" - and wiped on their very next
     * input event in the company field. That single mechanism is what killed all
     * three previous attempts.
     *
     * A `TWO:`-prefixed organisation number goes into the hidden `companyid` and its
     * `data-two-company-name` pairing tag like ANY OTHER, and is NOT written into the
     * visible identification (`dni`) field (Doug's ruling, TWO-40, Option A). That
     * one field is the only asymmetry - storage, pairing, the mirror, the session
     * record and the routing are all uniform. It is not a sole-trader concept either:
     * registered companies in some countries carry a `TWO:` identifier too, so the
     * rule is keyed on the value and never on how it was captured. The reasoning for
     * skipping `dni` (core's isDniLite validator rejects the value, our own reader
     * rejects it too, and the field belongs to the buyer) lives on
     * writeOrganizationToAddressIdentifiers(). An earlier round withheld the PAIRING
     * and the NAME as well, and every defect that followed came from that divergence;
     * this is deliberately not that.
     *
     * One value in the response IS deliberately not written, and it is a ruling
     * rather than an omission:
     *
     *  - the COUNTRY is not written at all, though the response carries one.
     *    `country_code` is the country the sole trader is REGISTERED in, while the
     *    enrolment's token - and the session company the completion has just
     *    stored through `saveCompany` - were minted against the country resolved
     *    from the LIVE FORM (decision #12). The server discards the whole session
     *    company the moment the saved country disagrees with the cart's
     *    invoice-address country, so writing the registered country over the
     *    form's would destroy the very enrolment this is completing. The two
     *    agreeing is the ordinary case and needs nothing; the two disagreeing is
     *    the case where writing it is actively wrong.
     *
     * Runs on the address-editor page and on the payment tile alike, and must keep
     * doing so: the address-editor page renders no `.two-sole-trader` container, so
     * anything gated on one silently no-ops there (the TWO-40 follow-up in
     * getCurrentBuyer() is the same trap). Only the status/prompt UI is
     * container-scoped, which is correct - there is nothing to show where there is
     * no container.
     *
     * @param {Object} buyer the `/autofill/v1/buyer/current` response
     * @returns {boolean} whether anything was written
     */
    adoptSoleTraderBuyer(buyer) {
        if (this._destroyed || !buyer || typeof buyer !== 'object') {
            return false;
        }
        const number = String(buyer.organization_number == null ? '' : buyer.organization_number).trim();
        if (!number) {
            return false;
        }
        // The name the buyer may SEE. Never applyBuyer()'s organisation-number
        // fallback label: that fallback exists precisely for a sole trader with no
        // trading name of their own, and it is exactly where the synthetic `TWO:`
        // identifier surfaces.
        const name = String(buyer.company_name == null ? '' : buyer.company_name).trim();

        // Scope, resolved ONCE and shared by every write below. Exactly
        // autoFillAddressIfNeeded()'s three states, for the reasons given there:
        // scoped-and-reported into the secondary address, SKIPPED where the invoice
        // form is on screen but could not be scoped to a single block, and the
        // original document-wide behaviour everywhere else.
        const secondaryRoot = this.secondaryAddressFormRoot();
        const scopelessInvoiceForm = !secondaryRoot
            && this.visibleAddressFormType() === 'invoice'
            && !this.visibleAddressFormRoot();

        // THE PIN IS DELIBERATELY NOT CONSULTED HERE. This reverses an earlier
        // review fix, and the reasoning is recorded so it is not "fixed" again.
        //
        // Round 1 added `secondaryAddressIsPinned()` as an early return, by analogy
        // with the invoice mirror. Round 2 showed the analogy is false, in two ways.
        // secondaryAddressFormRoot() resolves non-null ONLY when the invoice form is
        // the VISIBLE, editable form - so the pin here gates the form the buyer is
        // looking at and has just acted on, which is the opposite of what the pin is
        // for. And an invoice form core pre-filled from a saved address carries
        // street, postcode and city with nothing on record as having written them,
        // which reads as buyer-authored and pins by DEFAULT - so the adoption would
        // write nothing at all for every buyer editing an existing billing address.
        // That is the reported bug reinstated: "absolutely nothing is being
        // populated".
        //
        // The mirror's pin is right for the mirror because the mirror is a
        // cross-page-load carry-over into a form the buyer never asked it to touch.
        // This runs from an enrolment the buyer has just completed on the form in
        // front of them - the one case the pin was never meant to cover.

        let wrote = false;

        // The NAME is what makes the pair writable, and a blank one takes the whole
        // identity half of this adoption with it. Not a shortcut - the alternatives
        // are both defects this class has already been burned by:
        //
        //  - tag the number with whatever the buyer last typed in the search box.
        //    That is a mismatched name/number pairing, which makes
        //    hasConfirmedSelection() report a company the buyer never confirmed -
        //    named in `.ai/decisions.md` as one of the defect classes that got the
        //    three previous attempts withdrawn;
        //  - tag it with the empty string. clearStaleOrganizationSelection() reads
        //    an empty tag beside a set number as stale and wipes it on the next
        //    input event, so the write does not survive to be worth making.
        //
        // A nameless sole trader therefore reaches the order the way they already
        // did - through the session record `saveCompany` has just written and the
        // selection published beside this call - and the ADDRESS below is still
        // filled, because an address fill carries no pairing and no such hazard.
        //
        // Writing nothing is NOT the same as leaving the form alone, though, and
        // that distinction is a review finding rather than a subtlety: whatever
        // selection was standing before the buyer enrolled is still in the form -
        // hidden pair, tag, and the lookup's own identification number - all of it
        // belonging to a company the buyer has just moved off. The session and the
        // manager now say sole trader while the form still says the abandoned
        // company, and the resolver's address tier reads the form.
        //
        // Cleared field by field, and deliberately NOT through
        // clearSelectedCompany(). That method is the right shape but the wrong
        // reach here: it also calls clearPersistedCompany(), which POSTs a clear of
        // the SERVER session company - the very record `saveCompany` has just
        // written for this enrolment, moments earlier and asynchronously. The clear
        // would land after it and destroy the enrolment this method is completing.
        // (Its publishConfirmedSelection('', '') would be harmless, since
        // applyBuyer() republishes immediately after this returns, but the session
        // clear is not.) Form residue only, therefore, which is what is actually
        // stale.
        if (!name) {
            if (this.organizationField && this.organizationField.length) {
                this.organizationField.val('');
                this.organizationField.removeAttr('data-two-company-name');
            }
            this.setCompanyIdHint('');
            this.clearLookupWrittenAddressIdentifiers();
        }

        if (name && this.companyField && this.companyField.length > 0) {
            // UNCONDITIONAL, unlike the invoice mirror's own writes, and that is
            // the difference between the two features rather than an
            // inconsistency. The mirror writes into an address the buyer is not
            // looking at, so it must never overwrite an answer of theirs. This
            // runs off a signup the buyer has just completed in front of them,
            // having asked for exactly this - and the company field on the tile is
            // the SEARCH BOX, so a writability rule would refuse the one case the
            // whole flow exists for: a buyer who typed a name, found nothing, and
            // enrolled instead. Marked all the same, so every later pass in this
            // class can still tell the value is ours.
            //
            // NO `input`/`change` trigger, deliberately: that is what
            // clearStaleOrganizationSelection() is bound to, and firing it here -
            // between the new name landing and the tag below being written - would
            // have the guard judge the new name against the PREVIOUS selection's
            // tag and wipe the pair this method is in the middle of establishing.
            // A real search selection does not trigger on this field either.
            // Value written UNCONDITIONALLY rather than only when it differs, and
            // the marker set from the same statement, so the two can never disagree.
            // `this.companyField` is a document-wide selector: a getter reads the
            // FIRST match while `.attr()` marks EVERY one, so a "skip the write when
            // it already matches" test could mark a field it had not written - a
            // marker beside a value we did not put there is precisely what the pin
            // reads as tampering, and it would fire on this very page.
            this.companyField.val(name);
            this.companyField.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR, name);
            wrote = true;

            // The pairing. Tagged with the name now IN the field, which is the only
            // value clearStaleOrganizationSelection() accepts as a match.
            if (this.markOrganizationFieldSelected(name, number)) {
                wrote = true;
            }

            // Visible identification field. Bypasses the address-lookup switch
            // (TWO-40 follow-up, live bug reported by Doug 2026-08-12) for the
            // same reason autoFillSoleTraderAddress() below does: that switch is
            // forced off outright once company search lives in the payment tile
            // - the ONLY place TWO-40 puts the sole-trader entry point - so
            // leaving this gated left it permanently dead on every shop running
            // the current design. Still declines an internal (`TWO:`) identifier
            // - the common case for a sole trader - and answers `false` either
            // way; nothing here records the write, so there is nothing to keep
            // in step. The pairing above is what carries the selection.
            this.writeOrganizationToAddressIdentifiers(number, false, secondaryRoot || undefined, true);
        }

        // Street/building/apartment/postcode/city, then the region - which is applied
        // AFTER the fill, because on a form with no state field it appends to the CITY
        // the fill has just written, and must see the final value rather than the one
        // it replaced. Same scope gating for both.
        const source = (buyer.billing_address || buyer.shipping_address || null);
        const filled = scopelessInvoiceForm
            ? {}
            : Object.assign(
                {},
                this.autoFillSoleTraderAddress(buyer, secondaryRoot),
                (source && typeof source === 'object')
                    ? this.autoFillRegion(source, secondaryRoot, true)
                    : {}
            );
        if (Object.keys(filled).length > 0) {
            wrote = true;
        }

        // Report what went into the SECONDARY address, and only there - the pin
        // judges no other form, and autoFillAddressIfNeeded() draws the line in the
        // same place. Without this the next render sees non-empty fields with
        // nothing on record as having written them, reads them as buyer-authored,
        // and pins the whole address against future syncing.
        if (secondaryRoot) {
            this.recordMirrorWrites(Object.assign(
                {}, filled, this.soleTraderPairReport(name, number, secondaryRoot)
            ));
        }

        // Re-evaluate the dropdown's own rows after a state change, the same three
        // calls autoFillAddressIfNeeded() makes after its own write. Deliberately
        // NOT justified on hasConfirmedSelection() having changed answer: none of
        // the three reads it any more (they gate on the dropdown being open and on
        // country availability), and a comment claiming otherwise would send the
        // next reader looking for a dependency that is not there.
        this.syncNotListedVisibility();
        this.syncSoleTraderEntryVisibility();
        this.syncRegisteredEntryVisibility();

        // "Select a different sole trader" (TWO-40 follow-up): only once a
        // NAMED identity actually landed in the company field above - the
        // nameless branch earlier in this method clears that field instead,
        // and there is nothing to offer to "replace" in that case.
        if (name) {
            this.renderSelectDifferentSoleTraderLink();
        }

        return wrote;
    }

    /**
     * The registered-address half of adoptSoleTraderBuyer().
     *
     * `billing_address` is the registered address and is what fills the form.
     * `shipping_address` is a FALLBACK only, used when the response carries one and
     * no billing address - it is null in the completions captured so far, and a
     * null must never be allowed to blank anything.
     *
     * EVERY field of the response lands somewhere (Doug's ruling). `street`,
     * `building`, `apartment`, `postal_code` and `city` are handled here;
     * `region` is applied by autoFillRegion() after this returns, because on a form
     * with no state field it appends to the CITY this fill has just written.
     *
     * `address2` and `state` were added to MIRRORED_ADDRESS_FIELDS and to
     * `Twopayment::MIRROR_WRITE_SESSION_KEYS` so these writes stay ATTRIBUTABLE
     * across a page load, and so the pin judges them: a buyer typing a second
     * address line is stating an independent answer exactly as much as one typing a
     * city. A writable field missing from that record would have made the
     * address-wide rule miss real buyer-entered data.
     *
     * Beyond the line routing the address is handed to autoFillAddress()
     * untranslated, because that method already coalesces every key spelling this
     * response uses (`street`, `postal_code`, `city`) - one mapping, in one place,
     * for both callers.
     *
     * @param {Object} buyer
     * @param {?Element} secondaryRoot
     * @returns {Object} what the fill now owns, from autoFillAddress()
     */
    autoFillSoleTraderAddress(buyer, secondaryRoot) {
        const source = buyer.billing_address || buyer.shipping_address || null;
        if (!source || typeof source !== 'object') {
            return {};
        }

        const street = String(source.street == null ? '' : source.street).trim();
        const building = String(source.building == null ? '' : source.building).trim();
        const apartment = String(source.apartment == null ? '' : source.apartment).trim();

        // Doug's routing rule. Where a building or apartment is given it is the more
        // specific locator and takes the FIRST line, with the street moving to the
        // second; where neither is given the street takes the first line and the
        // second is left alone.
        //
        // With both present they are joined most-specific-first, which is how an
        // address is read aloud ("Apartment 4, Mill House").
        //
        // NO de-duplication against the street, on Doug's explicit ruling: it is
        // valid for an address to carry the same text on both lines, so suppressing a
        // second line that matches the first would be discarding real data. An
        // earlier round proposed exactly that and it was wrong.
        const locator = [apartment, building].filter(Boolean).join(', ');
        const resolved = Object.assign({}, source);
        if (locator) {
            resolved.street = locator;
            resolved.address_line_2 = street;
        }

        // `true`: bypass the address-lookup switch (TWO-40 follow-up, live bug
        // reported by Doug 2026-08-12). See autoFillAddress()'s own doc on the
        // parameter for why this call site, specifically, must never be gated
        // on it.
        return secondaryRoot
            ? this.autoFillAddress([resolved], secondaryRoot, true)
            : this.autoFillAddress([resolved], undefined, true);
    }

    /**
     * The state/county name an option VALUE in a state select stands for, or ''.
     *
     * Compared and recorded on the option's TEXT, never its value: PrestaShop state
     * ids are shop-local, so a record written on one shop would be meaningless on
     * another - the same reason the country is carried as an ISO code. See
     * mirroredAddressFieldStates().
     *
     * @param {HTMLSelectElement} select
     * @param {string} optionValue
     * @returns {string}
     */
    stateNameForOptionValue(select, optionValue) {
        const value = String(optionValue == null ? '' : optionValue).trim();
        if (!value || !select || !select.options) {
            return '';
        }
        for (let index = 0; index < select.options.length; index++) {
            if (select.options[index].value === value) {
                return String(select.options[index].text || '').trim();
            }
        }

        return '';
    }

    /**
     * The option VALUE in a state select whose name matches a free-text region, or
     * null when there is no such option.
     *
     * Best-effort by design, and the limits are worth stating: the autofill response
     * gives a region as a NAME with no code beside it, while PrestaShop needs a state
     * id. So the only available join is on the visible label, trimmed and
     * case-folded, with the two-letter abbreviation accepted as well because several
     * registries return "CA" where the shop shows "California". A region that
     * matches nothing writes nothing rather than guessing.
     *
     * @param {HTMLSelectElement} select
     * @param {string} region
     * @returns {?string}
     */
    stateOptionValueForRegion(select, region) {
        const target = this.normalizeMirroredValue(region);
        if (!target || !select || !select.options) {
            return null;
        }
        for (let index = 0; index < select.options.length; index++) {
            const option = select.options[index];
            if (!option.value) {
                continue;
            }
            const name = this.normalizeMirroredValue(option.text);
            const iso = this.normalizeMirroredValue(
                option.getAttribute('data-iso-code') || option.getAttribute('data-iso') || ''
            );
            if (name === target || (iso !== '' && iso === target)) {
                return option.value;
            }
        }

        return null;
    }

    /**
     * Put the response's `region` somewhere, on Doug's ruling that it must land
     * rather than be dropped (TWO-40).
     *
     * Two destinations, in order:
     *
     *  - the form's own state/county select, when the country has one. Matched on the
     *    visible name, best-effort - see stateOptionValueForRegion() for why that is
     *    the only join available;
     *  - otherwise appended to the CITY with a comma. Most countries render no state
     *    field at all (GB among them), and the alternative to appending is losing the
     *    region entirely.
     *
     * The city append writes through the same marker-and-record machinery as every
     * other field, so `"Ashford, Kent"` is attributable as ours and does not read as
     * buyer-authored on the next render. It deliberately does NOT append twice: the
     * value already carrying the region is left alone.
     *
     * @param {Object} source the response address
     * @param {?Element} root
     * @param {boolean} [bypassAddressLookupGate] see autoFillAddress()'s own
     *        doc on the identical parameter (TWO-40 follow-up, live bug
     *        reported by Doug 2026-08-12) - adoptSoleTraderBuyer() passes
     *        `true` here for the same reason it does there.
     * @returns {Object} partial record of what this wrote
     */
    autoFillRegion(source, root, bypassAddressLookupGate) {
        if (!bypassAddressLookupGate && !this.isAddressLookupEnabled()) {
            return {};
        }
        const region = String(source.region == null ? '' : source.region).trim();
        if (!region) {
            return {};
        }

        const scope = root ? $(root) : $(document);
        const select = scope.find("select[name='id_state'], select[name='state']").first();
        if (select.length > 0) {
            const optionValue = this.stateOptionValueForRegion(select[0], region);
            if (optionValue === null) {
                return {};
            }
            // '' rather than the server-rendered value, for the reason given in
            // mirroredAddressFieldStates(): a state select can legitimately be empty,
            // so a server-rendered state is the buyer's saved answer and the
            // registered region must not overwrite it. Only a value this class is on
            // record as having written there may be replaced.
            const accepted = this.mirrorWriteAcceptedValues(
                'state',
                '',
                name => {
                    const match = this.stateOptionValueForRegion(select[0], String(name));
                    return match === null ? '' : match;
                }
            );
            return this.writeMirroredValue(select, optionValue, accepted)
                ? { state: this.stateNameForOptionValue(select[0], optionValue) }
                : {};
        }

        const cityField = scope.find("input[name='city']").first();
        if (cityField.length === 0) {
            return {};
        }
        const current = String(cityField.val() == null ? '' : cityField.val()).trim();
        if (!current) {
            return {};
        }
        if (this.normalizeMirroredValue(current).endsWith(this.normalizeMirroredValue(region))) {
            // Already carries it - appending again would grow the value on every pass.
            return {};
        }
        const combined = current + ', ' + region;
        cityField.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR, combined);
        cityField.val(combined);
        cityField.trigger('input');
        cityField.trigger('change');

        return { city: combined };
    }

    /**
     * What of the name/number pair actually reached the secondary address, read
     * back off the form rather than assumed.
     *
     * Read back deliberately: both writes above can decline - an absent field, the
     * address-lookup switch being off, or the number being an internal (`TWO:`)
     * identifier, which never enters the visible `dni` field at all (TWO-40, Option
     * A) and is the common case for a sole trader. Reporting a value that never
     * landed is worse than reporting none: it tells the next render to treat a field
     * the buyer may yet fill as already ours.
     *
     * @param {string} name
     * @param {string} number
     * @param {Element} root
     * @returns {Object} partial mirror-write record
     */
    soleTraderPairReport(name, number, root) {
        const report = {};
        const companyField = $(root).find("input[name='company']").first();
        if (name && companyField.length > 0
            && String(companyField.val() == null ? '' : companyField.val()) === name) {
            report.company = name;
        }
        const placed = this.addressIdentifierFields(root).some(
            field => field && field.length > 0
                && String(field.val() == null ? '' : field.val()).trim() === number
                && field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR) === number
        );
        // THREE outcomes here, not two, and conflating any pair of them pins the
        // address. recordMirrorWrites() reads a reported '' as a positive statement -
        // "nothing of ours is in that field any more" - and merges it over the
        // record, while an ABSENT key means "unchanged".
        //
        //  - the number LANDED: report it, so the next render knows it is ours;
        //  - the field is EMPTY: report '', because that is true, and it is the only
        //    way to retract a number a previous mirror pass recorded writing there.
        //    This is the nameless-buyer branch above, which clears the field through
        //    clearLookupWrittenAddressIdentifiers(). Omitting the key here would
        //    leave the record claiming a number the form no longer holds - empty
        //    field against a non-empty record, which is exactly the mismatch the pin
        //    reads as buyer tampering;
        //  - the field holds SOMETHING ELSE - the buyer's own number, or one an
        //    earlier pass wrote and the gate has just declined to replace: say
        //    nothing. It is not ours to claim and not ours to retract.
        if (placed) {
            report.organization = number;
        } else if (this.addressIdentifierFields(root).some(
            field => field && field.length > 0
                && String(field.val() == null ? '' : field.val()).trim() === ''
        )) {
            report.organization = '';
        }

        return report;
    }

    persistCompanyToCookie(data) {
        try {
            if (!window.twopayment || !window.twopayment.order_intent_url || !window.twopayment.ajax_token) return;
            $.ajax({
                url: window.twopayment.order_intent_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax: 1,
                    action: 'saveCompany',
                    token: window.twopayment.ajax_token,
                    company: data.company,
                    companyid: data.companyid,
                    // Deliberately relayed even when unresolved. '' makes the
                    // controller's `if (!empty($country))` skip the country
                    // write, so with no prior marker the cookie ends up
                    // company + id and an EMPTY country. What happens to that
                    // half-record on the next read depends on the reader:
                    // twopayment.php's legacy-marker discard is gated on
                    // `!Tools::isEmpty($country_iso)`, so it DISCARDS the whole
                    // session company when the read path has an address country
                    // to compare against, and REUSES it when the read path has
                    // none - which is exactly the case orderintent.php hits
                    // when no checkout address is selected yet and it passes
                    // ''. Safe either way for the order payload, whose
                    // country_iso comes from the address server-side
                    // (Country::getIsoById), never from this cookie, so an
                    // empty value here can never reach the API as a guess.
                    country: this.getCurrentCountry(),
                    id_address: this.getCurrentAddressId()
                },
                timeout: 10000
            });
        } catch (e) {
            // no-op
        }
    }

    getCurrentAddressId() {
        const checkedAddressSelectors = [
            "input[name='id_address_invoice']:checked",
            "input[name='id_address_delivery']:checked"
        ];
        for (const selector of checkedAddressSelectors) {
            const field = document.querySelector(selector);
            if (field && field.value) {
                const parsed = parseInt(field.value, 10);
                if (parsed > 0) {
                    return parsed;
                }
            }
        }

        const addressForm = document.querySelector('.js-address-form form[data-id-address]');
        if (addressForm) {
            const attrValue = addressForm.getAttribute('data-id-address');
            const parsed = parseInt(attrValue || '0', 10);
            if (parsed > 0) {
                return parsed;
            }
        }

        const selectors = [
            "input[name='id_address_invoice']",
            "input[name='id_address_delivery']",
            "input[name='id_address']"
        ];

        for (const selector of selectors) {
            const field = document.querySelector(selector);
            if (field && field.value) {
                const parsed = parseInt(field.value, 10);
                if (parsed > 0) {
                    return parsed;
                }
            }
        }

        return 0;
    }

    /**
     * Check if module is available and initialized
     */
    isReady() {
        return this.isInitialized && this.companyField && this.companyField.length > 0;
    }
}

// Export for use in other modules
window.TwoCompanySearch = TwoCompanySearch;
// Published so a test can assert the enforced threshold against the number the
// hint shows the buyer. A top-level `const` in a classic script is not reachable
// from outside the script, and the whole point of a single constant is lost if a
// test has to hard-code the number to check it.
window.TwoCompanySearch.MIN_SEARCH_LENGTH = MIN_SEARCH_LENGTH;
