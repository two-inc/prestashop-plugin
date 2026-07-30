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
        this.createCompanyIdHintField();
        this.clearStaleOrganizationSelection();
        this.setupCompanyInputSync();
        this.setupAddressIdentifierSync();
        this.setupAutocomplete();
        this.setupCountryChangeListener();
        this.isInitialized = true;
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
     * Create or ensure the inline "selected company's org number" hint span
     * exists next to the company field (TWO-25288). Grey, informational
     * only - never a form field, never submitted.
     */
    createCompanyIdHintField() {
        let hintField = $('.two-company-id-hint');

        if (hintField.length === 0) {
            hintField = $('<span class="two-company-id-hint"></span>');
            this.companyField.after(hintField);

            // The hint is positioned absolutely (see two.css), which needs a
            // positioned ancestor. The company field's wrapper markup comes
            // from theme/core PrestaShop templates this plugin does not own,
            // so only force `relative` when the wrapper is still `static` -
            // never override a wrapper that already has its own positioning.
            const wrapper = this.companyField.parent();
            if (wrapper.length && wrapper.css('position') === 'static') {
                wrapper.css('position', 'relative');
            }
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
            this.companyIdHintField.text(value ? String(value) : '');
        }
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
            if (this.companyField && this.companyField.length > 0) {
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

        [$("input[name='dni']"), $("input[name='vat_number']")].forEach(field => {
            if (field.length === 0) {
                return;
            }
            if (onlyIfEmpty && String(field.val() || '').trim() !== '') {
                return;
            }
            field.val(value);
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
                try {
                    if (previousField.hasClass('ui-autocomplete-input')) {
                        previousField.autocomplete('destroy');
                    }
                } catch (e) {
                    // Already gone or never initialised; nothing to release.
                }
                previousField.removeClass(
                    'two-company-search-input two-company-search-loading ui-autocomplete-loading'
                );
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

        // Marks the field for the in-field spinner CSS (views/css/two.css).
        this.companyField.addClass('two-company-search-input');

        // Empty-field hint. Set here rather than only in the address-form
        // override so it survives PrestaShop replacing the input on
        // `updatedAddressForm`, and so it reaches themes that supply their own
        // address form and never run that override. Before the path branch, so
        // both render paths get it.
        this.applyEmptyFieldHint();

        // Use jQuery UI autocomplete if available; otherwise fallback to custom.
        // `$.fn.autocomplete` alone is not proof of jQuery UI - the older
        // bassistance jquery.autocomplete plugin claims the same name with an
        // incompatible signature, and feeding it this options object would leave
        // the field with no working search at all while skipping the fallback.
        if ($.ui && $.ui.autocomplete && typeof $.fn.autocomplete === 'function') {
            this.companyField.autocomplete({
                source: (request, response) => {
                    const term = String(request.term || '');
                    // Empty field: the placeholder is already the hint for this
                    // state, so opening a dropdown to repeat it would be noise.
                    if (term.length === 0) {
                        response([]);
                        return;
                    }
                    // Typed, but not enough to search on. Say so instead of
                    // leaving the buyer with a field that appears to do nothing.
                    // Trimmed: whitespace is not something the search can match
                    // on, so "   " must be told to type more rather than put on
                    // the wire while "  " is refused.
                    if (term.trim().length < MIN_SEARCH_LENGTH) {
                        response([this.buildTooShortItem()]);
                        return;
                    }
                    const key = this.buildCacheKey(request.term);
                    const cached = TwoCompanySearch.cacheGet(key);
                    if (cached) {
                        response(cached);
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
                        if (!(meta && meta.degraded)) {
                            TwoCompanySearch.cacheSet(key, results);
                        }
                        response(results);
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
                select: (event, ui) => {
                    // The "search unavailable" row is a message, not a company:
                    // returning false stops jQuery UI writing it into the field.
                    if (ui && ui.item && ui.item.two_unavailable) {
                        return false;
                    }
                    return this.onCompanySelected(event, ui);
                },
                focus: (event, ui) => {
                    // Likewise keep it out of the field on keyboard navigation.
                    if (ui && ui.item && ui.item.two_unavailable) {
                        return false;
                    }
                }
            });

            // Render the unavailable row as non-selectable. `ui-state-disabled`
            // is what jQuery UI's menu itself checks, so the row is skipped by
            // keyboard navigation rather than merely being refused on select.
            //
            // Company names always go through .text(), matching jQuery UI's own
            // default renderer, so a name containing markup cannot inject HTML
            // into the dropdown.
            //
            // Wrapped: `autocomplete('instance')` only exists from jQuery UI
            // 1.11, and an unknown-method call throws. A theme shipping an older
            // jQuery UI must lose the styling of this row, not the whole company
            // search - select/focus below already refuse the row without it.
            //
            // Patched at most ONCE per widget instance. jQuery UI's widget
            // bridge does not build a fresh instance when `.autocomplete({...})`
            // is called on an already-initialised field - it runs option()+
            // _init() on the existing one - and this method is re-invoked on
            // every country change and address-form update. Without the guard
            // each call would capture the previous wrapper and wrap it again,
            // nesting one layer deeper every time until rendering a row blew the
            // stack. A destroyed-and-recreated widget is a new instance and
            // carries no flag, so it is patched again as intended.
            try {
                const instance = this.companyField.autocomplete('instance');
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
                        // DOM (the too-short hint is not a failure) while keeping
                        // the disabled/keyboard-skip behaviour identical.
                        // `two-autocomplete-message` is emitted alongside it and
                        // is what the stylesheet keys the muted colour, the
                        // default cursor and the hover suppression on - so every
                        // message row looks like a message whatever its cause.
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

    setupCustomAutocomplete() {
        const inputEl = this.companyField.get(0);
        if (!inputEl) return;

        // This runs again on every country change and address-form update, so
        // without tearing the previous one down each call left an orphan
        // dropdown behind whose input listener still fired - duplicate searches
        // and duplicate spinner toggles on the one shared field, of which
        // destroy() could only ever clean up the most recent.
        this.teardownCustomAutocomplete();

        // Create suggestion container
        let container = document.createElement('div');
        container.className = 'two-autocomplete-container';
        container.style.position = 'relative';
        inputEl.parentNode.insertBefore(container, inputEl.nextSibling);

        let list = document.createElement('div');
        list.className = 'two-autocomplete-list';
        list.style.position = 'absolute';
        list.style.zIndex = '1000';
        list.style.background = '#fff';
        list.style.border = '1px solid #ccc';
        list.style.width = (inputEl.offsetWidth || 280) + 'px';
        list.style.display = 'none';
        list.style.maxHeight = '240px';
        list.style.overflowY = 'auto';
        container.appendChild(list);

        // jQuery UI is absent on this path, so nothing toggles the loading class
        // for us; do it by hand against the same CSS the live path uses.
        const setLoadingState = (isLoading) => {
            this.companyField.toggleClass('two-company-search-loading', !!isLoading);
        };

        const renderResults = (items) => {
            setLoadingState(false);
            list.innerHTML = '';
            if (!items || items.length === 0) {
                list.style.display = 'none';
                return;
            }
            items.forEach((item) => {
                const row = document.createElement('div');
                row.className = 'two-autocomplete-item';
                row.style.padding = '6px 10px';
                row.style.cursor = 'pointer';
                row.style.whiteSpace = 'nowrap';
                row.style.overflow = 'hidden';
                row.style.textOverflow = 'ellipsis';
                row.textContent = item.label || item.value || '';
                row.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const ui = { item: { value: item.value, lookup_id: item.lookup_id, organization_number: item.organization_number } };
                    this.onCompanySelected(null, ui);
                    list.style.display = 'none';
                });
                list.appendChild(row);
            });
            list.style.display = 'block';
        };

        // Minimal loading indicator so a slow-but-alive request is visually
        // distinguishable from a dead one (goal: no spinner component, just
        // a text row matching the existing list rendering).
        const searchingText = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_search_searching)
            || 'Searching...';
        const renderLoading = () => {
            setLoadingState(true);
            list.innerHTML = '';
            const row = document.createElement('div');
            row.className = 'two-autocomplete-item two-autocomplete-loading';
            row.style.padding = '6px 10px';
            row.style.color = '#888';
            row.style.fontStyle = 'italic';
            row.textContent = searchingText;
            list.appendChild(row);
            list.style.display = 'block';
        };

        // Failure state for this path. Same reasoning as buildUnavailableItem():
        // an empty list would read as "company not registered".
        const renderUnavailable = () => {
            setLoadingState(false);
            list.innerHTML = '';
            const row = document.createElement('div');
            row.className = 'two-autocomplete-item two-autocomplete-message two-autocomplete-unavailable';
            row.style.padding = '6px 10px';
            row.style.color = '#888';
            row.textContent = this.getSearchUnavailableText();
            list.appendChild(row);
            list.style.display = 'block';
        };

        // Too short to search on. Not a failure, so it gets its own class - but
        // it is rendered as a row on this path too, because the alternative is
        // the empty dropdown this path used to show below the threshold.
        const renderTooShort = () => {
            setLoadingState(false);
            list.innerHTML = '';
            const row = document.createElement('div');
            row.className = 'two-autocomplete-item two-autocomplete-message two-autocomplete-too-short';
            row.style.padding = '6px 10px';
            row.style.color = '#888';
            row.textContent = this.getTooShortText();
            list.appendChild(row);
            list.style.display = 'block';
        };

        // Same reasoning, different cause: no search was made because the
        // country is unknown, so point at the fix the buyer can apply.
        const renderSelectCountry = () => {
            setLoadingState(false);
            list.innerHTML = '';
            const row = document.createElement('div');
            row.className = 'two-autocomplete-item two-autocomplete-message two-autocomplete-select-country';
            row.style.padding = '6px 10px';
            row.style.color = '#888';
            row.textContent = this.getSelectCountryText();
            list.appendChild(row);
            list.style.display = 'block';
        };

        // Debounced input. Held in a named ref so teardown can unbind it: the
        // listener is on the shared company field, not on the container, so
        // dropping the container would leave it firing.
        // Mutable holder rather than a bare `let` so teardown can reach the
        // pending timer. A debounce tick that survives teardown would call
        // searchCompanies(), which bumps the sequence and aborts the NEW
        // dropdown's in-flight request - that request then resolves as `silent`,
        // so its spinner is never cleared and the buyer is left on a permanent
        // "Searching..." row while the orphan renders into a removed list.
        const debounce = { id: null };
        const onInput = () => {
            // Defence in depth: teardown unbinds this listener, but a destroyed
            // instance must not search even if an unbind was somehow missed.
            if (this._destroyed) {
                return;
            }
            const term = inputEl.value || '';
            clearTimeout(debounce.id);
            debounce.id = setTimeout(() => {
                // Empty field: the placeholder is the hint for this state, so
                // close the list rather than repeat it in a row.
                if (term.length === 0) {
                    renderResults([]);
                    return;
                }
                // Trimmed, for the same reason as the jQuery UI guard above.
                if (term.trim().length < MIN_SEARCH_LENGTH) {
                    renderTooShort();
                    return;
                }
                const key = this.buildCacheKey(term);
                const cached = TwoCompanySearch.cacheGet(key);
                if (cached) {
                    renderResults(cached);
                    return;
                }
                renderLoading();
                this.searchCompanies(term, (results, meta) => {
                    if (meta && meta.silent) {
                        // Superseded or aborted. A newer request owns the UI and
                        // has already re-armed the spinner, so leave the loading
                        // state alone - clearing it here would kill the spinner
                        // for the request still in flight.
                        return;
                    }
                    // Discard results if the input has moved on to a different
                    // term since this request was fired (belt-and-braces on
                    // top of the sequence/abort guard in searchCompanies()).
                    // The spinner must still be cleared: this branch is reached
                    // when the term changed WITHOUT a newer search superseding
                    // it - a programmatic val('') on country change fires no
                    // input event, so nothing else would ever clear it.
                    if ((inputEl.value || '') !== term) {
                        setLoadingState(false);
                        return;
                    }
                    if (meta && meta.countryUnresolved) {
                        renderSelectCountry();
                        return;
                    }
                    if (meta && meta.unavailable) {
                        renderUnavailable();
                        return;
                    }
                    if (!(meta && meta.degraded)) {
                        TwoCompanySearch.cacheSet(key, results);
                    }
                    renderResults(results);
                });
            }, 300);
        };

        const onBlur = () => {
            setTimeout(() => { list.style.display = 'none'; }, 150);
        };

        inputEl.addEventListener('input', onInput);
        inputEl.addEventListener('blur', onBlur);

        // Save for cleanup
        this._customAutocomplete = { container, list, inputEl, onInput, onBlur, debounce };
    }

    /**
     * Remove the custom (non-jQuery-UI) dropdown and unbind its listeners.
     *
     * Safe to call when nothing is set up, and idempotent - it is used both to
     * make setupCustomAutocomplete() re-entrant and on destroy().
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
        // Clear the fallback path's spinner class here, not just in destroy().
        // This method also runs when setup switches from the custom path to the
        // jQuery-UI one (a theme that loads jQuery UI late), and that branch only
        // ever touches `ui-autocomplete-loading` - so a spinner armed by the
        // custom path would otherwise keep running on a field with no dropdown,
        // which is the very failure this teardown exists to prevent.
        if (this.companyField && this.companyField.length) {
            this.companyField.removeClass('two-company-search-loading');
        }
        if (existing.inputEl) {
            if (existing.onInput) {
                existing.inputEl.removeEventListener('input', existing.onInput);
            }
            if (existing.onBlur) {
                existing.inputEl.removeEventListener('blur', existing.onBlur);
            }
        }
        if (existing.list && existing.list.parentNode) {
            existing.list.parentNode.removeChild(existing.list);
        }
        if (existing.container && existing.container.parentNode) {
            existing.container.parentNode.removeChild(existing.container);
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
            this.fetchCompanyDetails(ui.item.lookup_id)
                .then(details => {
                    this.autoFillAddressIfNeeded(details);
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
    autoFillAddressIfNeeded(details) {
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
            if (natIdVal) {
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
                }
            }
            // Find addresses list in various shapes
            const addresses = (details && (details.addresses || (details.company && details.company.addresses))) || [];
            if (Array.isArray(addresses) && addresses.length > 0) {
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
                
                if (this.companyField && this.companyField.length > 0) {
                    if (this.companyField.hasClass('ui-autocomplete-input')) {
                        this.companyField.autocomplete('close');
                    }
                    this.companyField.val('');
                }
                if (this.organizationField) {
                    this.organizationField.val('');
                    this.organizationField.removeAttr('data-two-company-name');
                }
                this.setCompanyIdHint('');
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
            // Drop the spinner classes. The abort above resolves no handler, so
            // without this a teardown mid-search leaves a spinner running in a
            // field that is no longer searching anything.
            if (this.companyField && this.companyField.length) {
                this.companyField.removeClass(
                    'two-company-search-input two-company-search-loading ui-autocomplete-loading'
                );
            }
            // Destroy autocomplete instance if present
            if (this.companyField && this.companyField.length && this.companyField.hasClass('ui-autocomplete-input')) {
                this.companyField.autocomplete('destroy');
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
