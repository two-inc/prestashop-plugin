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
        this._notListedButton = null;
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
            const text = value ? String(value) : '';
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
        const query = $('<input type="text" class="two-company-dropdown__query" autocomplete="off" />')
            .attr('placeholder', TwoCompanySearch.getEmptyFieldHintText())
            .attr('aria-label', TwoCompanySearch.getEmptyFieldHintText())
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

        const notListed = $('<button type="button" class="two-company-not-listed"></button>')
            .text(this.getManualEntryText());

        panel.append(searchRow).append(results).append(notListed);
        // After the company field, so DOM order === tab order (see above).
        // `appendTo` the wrapper rather than `.after()` the input: the
        // org-number hint is also a child of this wrapper and the panel must
        // come after it, not between the input and its own hint.
        wrapper.append(panel);

        this._dropdown = panel;
        this._queryField = query;
        this._resultsList = results;
        this._notListedButton = notListed;

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
                this.enterManualEntryMode();
            });
        }

        this._dropdown.on('keydown.twoDropdown', (event) => {
            // Enter inside an OPEN panel never reaches the address form.
            //
            // This handler is on the panel, so it runs after the widget's own
            // input handler has had the keystroke - jQuery UI only
            // `preventDefault`s Enter when it has an active menu item, and the
            // fallback engine only when it has an active row. In every other
            // state (the too-short hint, "No matches found", results painted
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
     * blur, and the too-short hint lives in that same container); the button
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
        this.syncNotListedVisibility();
        this._queryField.trigger('focus');
        // Render the current state immediately - for an empty query that is
        // the "type N more characters" hint (§1), not an empty or absent
        // panel. Matches the requirement that the hint is visible as soon as
        // the control opens, which is the Hyvä failure recorded on this
        // ticket.
        this.openSearchForCurrentTerm();
    }

    /**
     * Close the panel.
     *
     * @param {boolean} returnFocus Put focus back on the company-name field.
     *   True for Escape and for a completed selection (§1); false when the
     *   browser has already moved focus somewhere else of its own accord.
     */
    closeDropdown(returnFocus) {
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
     * §2 visibility gating for "My company is not on the list".
     *
     * "Search UI open and nothing captured yet" - NOT "the query is long
     * enough to have searched". Doug's requirement is explicit that a buyer
     * must have a route into manual entry without typing a doomed query
     * first, and the WC regression recorded on TWO-25326 was exactly this:
     * gating on the 3-character threshold removed the button from the DOM for
     * a buyer who had typed nothing, which is the case the bullet is about.
     */
    syncNotListedVisibility() {
        if (!this._notListedButton || !this._notListedButton.length) {
            return;
        }
        const show = this._dropdownOpen && !this._manualEntry && !this.hasConfirmedSelection();
        if (show) {
            this._notListedButton.show();
        } else {
            this._notListedButton.hide();
        }
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
            // single code point exactly when the keypress produced text.
            if (key && key.length === 1 && this._queryField && this._queryField.length) {
                this._queryField.val(key);
                this._queryField.trigger('input');
            }
        });
    }

    normalizeCompanyName(value) {
        return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
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

        if (!company) {
            this.organizationField.val('');
            this.organizationField.removeAttr('data-two-company-name');
            this.setCompanyIdHint('');
            return;
        }

        // If companyid exists but has no selection marker, treat it as stale after address/form re-renders.
        if (!taggedCompany) {
            this.organizationField.val('');
            this.setCompanyIdHint('');
            return;
        }

        if (this.normalizeCompanyName(company) !== this.normalizeCompanyName(taggedCompany)) {
            this.organizationField.val('');
            this.organizationField.removeAttr('data-two-company-name');
            this.setCompanyIdHint('');
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
        });
    }

    /**
     * Repaint the tile's read-only company summary (TWO-25288).
     *
     * Called at each point where this module changes the captured pair, because
     * every one of those writes goes through jQuery's `.val()` / `.attr()`, which
     * fire no event - so the summary module has nothing it could observe and has
     * to be told. It re-reads the DOM itself; nothing is passed in.
     *
     * Guarded rather than assumed present: this module ships and runs on the
     * address step, where the payment tile does not exist yet.
     */
    refreshCompanySummary() {
        try {
            if (window.TwoCompanySummary && typeof window.TwoCompanySummary.render === 'function') {
                window.TwoCompanySummary.render();
            }
        } catch (e) {
            // Display only. It must never break the capture it describes.
        }
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
     * flow needs, are governed by companySearchEnabled and stay live either
     * way.
     */
    isAddressLookupEnabled() {
        return this.config.addressLookupEnabled !== false;
    }

    /**
     * Single gate for the DNI / vat_number writes the lookup performs - the
     * selection handler, the company-details refinement, and the pre-submit
     * sync all go through here rather than each carrying its own condition.
     *
     * @param {string} orgNumber
     * @param {boolean} [onlyIfEmpty] Leave a value the customer typed alone.
     */
    writeOrganizationToAddressIdentifiers(orgNumber, onlyIfEmpty) {
        if (!this.isAddressLookupEnabled()) {
            return;
        }

        const value = String(orgNumber || '').trim();
        if (!value) {
            return;
        }

        this.addressIdentifierFields().forEach(field => {
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
        });
    }

    /**
     * The two address inputs a company selection mirrors its organisation number
     * into.
     *
     * One list rather than the selector pair written twice, because the clear has
     * to walk exactly the fields the write walks. A field present in one list and
     * absent from the other is a disowned organisation number left in the form.
     *
     * @returns {Array<Object>} jQuery objects, any of which may be empty
     */
    addressIdentifierFields() {
        return [$("input[name='dni']"), $("input[name='vat_number']")];
    }

    /**
     * Drop the identification / VAT numbers, but ONLY the ones the lookup itself
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
                    // Too short to search on - INCLUDING the empty query the
                    // panel opens with. TWO-25326 §1 requires the "type N more
                    // characters" hint to be on screen as soon as the control
                    // opens, not only once the buyer has typed a character
                    // (that is the Hyva failure recorded on the ticket), so
                    // the empty case is not special-cased into a hint of its
                    // own any more - it IS the too-short case, and says the
                    // same thing the buyer will keep reading until they have
                    // typed enough. Trimmed: whitespace is not something the
                    // search can match on.
                    if (term.trim().length < MIN_SEARCH_LENGTH) {
                        response([this.buildTooShortItem()]);
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
                // leaving the threshold here would make the too-short hint
                // unreachable by construction - the widget would swallow those
                // keystrokes silently, which is the behaviour TWO-25288 removes.
                // The threshold has moved into the `source` guard above, which is
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
                        // DOM (the too-short hint is not a failure, and neither
                        // is "No matches found") while keeping the
                        // disabled/keyboard-skip behaviour identical.
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
     * buildTooShortItem() uses it: that flag means "not a company", so
     * select / focus / _renderItem keep it out of the field and the keyboard
     * skips it.
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
     * @returns {string} wording for the manual-entry row
     */
    getManualEntryText() {
        return (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_manual_entry)
            || 'My company is not on the list';
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
     * into the address form's identification-number and VAT-number inputs, and
     * the server reads those off the saved address on a path of their own,
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
        this.refreshCompanySummary();
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
     * Message shown when the term is too short to search on (TWO-25288).
     *
     * Below the threshold PrestaShop used to show nothing at all - jQuery UI
     * simply never opened its menu - which is indistinguishable from a search
     * that ran and found nothing.
     *
     * A FIXED number, not a countdown of characters still needed. A count that
     * changes on every keystroke reads as an error being repeatedly re-raised,
     * and it has to be recomputed at every call site, which is exactly where a
     * claimed threshold drifts from the enforced one. `%d` is interpolated from
     * MIN_SEARCH_LENGTH so the number the buyer reads IS the number the guards
     * apply.
     *
     * @returns {string}
     */
    getTooShortText() {
        const template = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_too_short)
            || 'Please enter %d or more characters';
        return String(template).replace('%d', String(MIN_SEARCH_LENGTH));
    }

    /**
     * Pseudo-result carrying the too-short message through jQuery UI's
     * result-list plumbing.
     *
     * Reuses `two_unavailable` for the same reason buildSelectCountryItem() does:
     * that flag is what the select / focus / _renderItem handlers check to keep a
     * message row out of the company field. It means "not a company".
     *
     * `two_row_class` overrides only the row's own class so this row is
     * distinguishable in the DOM from a genuine failure - the disabled/keyboard
     * behaviour is shared and must stay shared.
     *
     * @returns {Object}
     */
    buildTooShortItem() {
        return {
            label: this.getTooShortText(),
            value: '',
            two_unavailable: true,
            two_row_class: 'two-autocomplete-too-short'
        };
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
            // Rendered SYNCHRONOUSLY, outside the debounce, and this is the
            // point of it: openDropdown() reaches this path by dispatching an
            // `input` event, so a too-short state deferred by 300ms leaves the
            // panel blank for 300ms every time it opens. §1 wants the hint on
            // screen as the control opens. There is no request to debounce in
            // this branch anyway - the whole reason it exists is that no
            // search will be made.
            if (term.trim().length < MIN_SEARCH_LENGTH) {
                debounce.id = null;
                setLoadingState(false);
                renderRows([messageRow(this.buildTooShortItem())]);
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
        const searchUrl = `${this.config.checkoutHost}/companies/v2/company?${params}`;

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
                    const displayLabel = orgNumber ? `${company.name} (${orgNumber})` : company.name;
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
     * @returns {string} uppercase ISO code, or '' when unresolvable
     */
    getCurrentCountry() {
        const countryField = document.querySelector("select[name='id_country']");
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

        // Unresolvable. searchCompanies() declines to search rather than
        // sending a country it invented - see the countryUnresolved branch
        // there for why omitting the parameter is not an option either.
        return '';
    }

    /**
     * Extract country code from country name text
     */
    extractCountryFromText(text) {
        const countryMap = {
            'united kingdom': 'GB', 'great britain': 'GB', 'uk': 'GB', 'england': 'GB',
            'spain': 'ES', 'españa': 'ES', 'espagne': 'ES',
            'france': 'FR', 'francia': 'FR',
            'germany': 'DE', 'deutschland': 'DE', 'alemania': 'DE',
            'italy': 'IT', 'italia': 'IT', 'italie': 'IT',
            'netherlands': 'NL', 'holland': 'NL', 'países bajos': 'NL',
            'belgium': 'BE', 'bélgica': 'BE', 'belgique': 'BE',
            'united states': 'US', 'usa': 'US', 'estados unidos': 'US',
            'canada': 'CA', 'canadá': 'CA',
            'australia': 'AU'
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
            this.organizationField.val(ui.item.organization_number);
            this.organizationField.attr('data-two-company-name', ui.item.value);
            this.setCompanyIdHint(ui.item.organization_number);

            // Persist for reliability across steps
            this.persistCompanyToCookie({
                company: ui.item.value,
                companyid: ui.item.organization_number
            });

            // Also sync to the DNI / VAT fields - gated on the address-lookup
            // toggle inside the writer (TWO-25203). Unconditional overwrite so
            // a re-search replaces the previous company's number.
            this.writeOrganizationToAddressIdentifiers(ui.item.organization_number);
        } else {
            // No org number on this result (e.g. GB, resolved later via
            // fetchCompanyDetails/lookup_id) - don't show a stale hint from a
            // previous selection in the meantime.
            this.setCompanyIdHint('');
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

        this.refreshCompanySummary();

        // §2 gating: a company is now captured, so "My company is not on the
        // list" must be hidden. LAST, deliberately - the org number and its
        // tag are what hasConfirmedSelection() reads, and both are committed
        // by this point on every branch above (immediate org number, or none
        // at all, in which case the deferred GB path re-syncs from
        // autoFillAddressIfNeeded() below).
        this.syncNotListedVisibility();

        return true;
    }

    /**
     * Fetch detailed company information
     */
    fetchCompanyDetails(lookupId) {
        // Direct Two API call from frontend as required
        const detailUrl = `${this.config.checkoutHost}/companies/v2/company/${lookupId}`;
        
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
                    this.organizationField.val(natIdVal);
                    this.organizationField.attr('data-two-company-name', this.companyField ? this.companyField.val() : '');
                    this.setCompanyIdHint(natIdVal);
                    this.writeOrganizationToAddressIdentifiers(natIdVal);
                    // Persist to cookie so backend can use it during order placement
                    this.persistCompanyToCookie({
                        company: this.companyField ? this.companyField.val() : '',
                        companyid: natIdVal
                    });
                    // The GB path: the selection carried no organisation number
                    // and this is the first point one exists, so the summary
                    // rendered at selection time showed a blank number slot.
                    this.refreshCompanySummary();
                    // §2 gating reads hasConfirmedSelection(), which only
                    // becomes true once the tag written two lines up exists -
                    // so on the GB path this, not onCompanySelected(), is
                    // where the "not on the list" button finally hides.
                    this.syncNotListedVisibility();
                }
            }
            // Find addresses list in various shapes. Gated by the SAME
            // stillOnSameCompany check as the organisation number above - a
            // stale deferred lookup overwriting street/city/postcode with an
            // abandoned company's address is exactly the same hazard, just on
            // different fields, and this response can carry both.
            const addresses = (details && (details.addresses || (details.company && details.company.addresses))) || [];
            if (Array.isArray(addresses) && addresses.length > 0 && stillOnSameCompany) {
                this.autoFillAddress(addresses);
            }
        } catch (e) {
            // ignore
        }
    }
    
    
    /**
     * Auto-fill address fields with company address data
     */
    autoFillAddress(addresses) {
        // Single gate for the address-field writes (TWO-25203). Both call
        // paths into the fill land here.
        if (!this.isAddressLookupEnabled()) {
            return;
        }

        // Prefer business/registered/visiting; fallback to first
        const address = addresses.find(addr => (addr.type && (
            String(addr.type).toUpperCase().includes('BUSINESS') ||
            String(addr.type).toUpperCase().includes('REGISTERED') ||
            String(addr.type).toUpperCase().includes('VISITING')
        ))) || addresses[0];
        if (!address) return;
        // Normalize key variants
        const street = address.street_address || address.streetAddress || address.street || address.address_line_1 || address.addressLine1 || '';
        const postal = address.postal_code || address.postalCode || address.zip || address.zip_code || '';
        const city = address.city || address.locality || '';
        const fieldMappings = {
            'address1': street,
            'postcode': postal,
            'city': city
        };
        Object.entries(fieldMappings).forEach(([fieldName, value]) => {
            const field = $(`input[name='${fieldName}']`);
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
                }
                return;
            }

            // Record the value as ours even when it already matches, so a later
            // fill can still recognise it as autofilled rather than typed.
            field.attr(TwoCompanySearch.AUTOFILL_MARKER_ATTR, incoming);
            if (current !== incoming) {
                field.val(incoming);
                field.trigger('input');
                field.trigger('change');
            }
        });
    }
    
    /**
     * Company data is now handled by form fields - no complex server persistence needed
     * PrestaShop's standard address saving will handle persistence automatically
     */
    
    /**
     * Get current company data from form fields (simplified)
     */
    getCompanyData() {
        return {
            company: this.companyField ? this.companyField.val() : '',
            companyid: this.organizationField ? this.organizationField.val() : ''
        };
    }
    
    /**
     * Check if current company data is valid
     */
    isValidCompanyData() {
        const data = this.getCompanyData();
        return !!(data.company && data.companyid);
    }
    
    /**
     * Reset company selection
     */
    reset() {
        if (this.companyField) this.companyField.val('');
        if (this.organizationField) {
            this.organizationField.val('');
            this.organizationField.removeAttr('data-two-company-name');
        }
        this.setCompanyIdHint('');
    }
    
    /**
     * Store company data in session storage for persistence across checkout steps
     */
    storeCompanyDataInSession(companyData) {
        try {
            sessionStorage.setItem('two_company_data', JSON.stringify(companyData));
            // Company data stored in session storage
        } catch (e) {
            
        }
    }

    /**
     * Retrieve company data from session storage
     */
    getCompanyDataFromSession() {
        // Deprecated in favor of cookie persistence via controller
        return null;
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

                if (this.companyField && this.companyField.length > 0) {
                    this.companyField.val('');
                }
                if (this.organizationField) {
                    this.organizationField.val('');
                    this.organizationField.removeAttr('data-two-company-name');
                }
                this.setCompanyIdHint('');
                // Recreate autocomplete to ensure new country is used immediately
                this.setupAutocomplete();
                // The clears above go through `.val()` / `.removeAttr()` and
                // fire no event, so the tile's summary would keep showing the
                // company this country change has just discarded (TWO-25288). On
                // core themes PrestaShop's own `updatedAddressForm` repaints it a
                // few hundred ms later; a theme that does not re-render the
                // address form on a country change never would.
                this.refreshCompanySummary();
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
