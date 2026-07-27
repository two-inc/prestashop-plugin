/**
 * Two Company Search Module - Clean, focused company selection
 * Handles company autocomplete, organization number persistence, and address saving
 */
class TwoCompanySearch {
    static DEFAULT_COMPANY_SEARCH_LIMIT = 50;

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
            ...config
        };
        
        this.companyField = null;
        this.organizationField = null;
        this.isInitialized = false;
        this.countryListener = null;

        // Race-condition guards for company search (see searchCompanies())
        this._companySearchSeq = 0;
        this._companySearchXhr = null;

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
            return;
        }

        // If companyid exists but has no selection marker, treat it as stale after address/form re-renders.
        if (!taggedCompany) {
            this.organizationField.val('');
            return;
        }

        if (this.normalizeCompanyName(company) !== this.normalizeCompanyName(taggedCompany)) {
            this.organizationField.val('');
            this.organizationField.removeAttr('data-two-company-name');
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
        const vatField = $("input[name='vat_number']");

        const dniValue = dniField.length > 0 ? String(dniField.val() || '').trim() : '';
        const vatValue = vatField.length > 0 ? String(vatField.val() || '').trim() : '';

        // If user already filled DNI manually, reuse it as fallback org number for Two flow.
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

        if (dniField.length > 0 && !dniValue) {
            dniField.val(orgNumber);
        }

        if (vatField.length > 0 && !vatValue) {
            vatField.val(orgNumber);
        }
    }
    
    /**
     * Setup jQuery UI Autocomplete for company search
     */
    setupAutocomplete() {
        if (!this.config.checkoutHost) {
            
            return;
        }

        // Shared cache for both jQuery UI and custom autocomplete
        const cache = new Map();
        const TTL_MS = 5 * 60 * 1000;
        const now = () => Date.now();

        // Use jQuery UI autocomplete if available; otherwise fallback to custom
        if (typeof $.fn.autocomplete === 'function') {
            this.companyField.autocomplete({
                source: (request, response) => {
                    const key = request.term + '|' + (this.getCurrentCountry() || 'GB');
                    const cached = cache.get(key);
                    if (cached && (now() - cached.t) < TTL_MS) {
                        response(cached.v);
                        return;
                    }
                    this.searchCompanies(request.term, (results) => {
                        cache.set(key, { v: results, t: now() });
                        response(results);
                    });
                },
                minLength: 3,
                delay: 300,
                select: (event, ui) => {
                    return this.onCompanySelected(event, ui);
                }
            });

            if (!this.companyField.hasClass('ui-autocomplete-input')) {
                
            }
        } else {
            
            this.setupCustomAutocomplete(cache, TTL_MS, now);
        }
    }

    setupCustomAutocomplete(cache, TTL_MS, now) {
        const inputEl = this.companyField.get(0);
        if (!inputEl) return;

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

        const renderResults = (items) => {
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

        // Debounced input
        let debounceId = null;
        inputEl.addEventListener('input', () => {
            const term = inputEl.value || '';
            clearTimeout(debounceId);
            debounceId = setTimeout(() => {
                if (term.length < 3) {
                    renderResults([]);
                    return;
                }
                const key = term + '|' + (this.getCurrentCountry() || 'GB');
                const cached = cache.get(key);
                if (cached && (now() - cached.t) < TTL_MS) {
                    renderResults(cached.v);
                    return;
                }
                renderLoading();
                this.searchCompanies(term, (results) => {
                    // Discard results if the input has moved on to a different
                    // term since this request was fired (belt-and-braces on
                    // top of the sequence/abort guard in searchCompanies()).
                    if ((inputEl.value || '') !== term) {
                        return;
                    }
                    cache.set(key, { v: results, t: now() });
                    renderResults(results);
                });
            }, 300);
        });

        inputEl.addEventListener('blur', () => {
            setTimeout(() => { list.style.display = 'none'; }, 150);
        });

        // Save for cleanup
        this._customAutocomplete = { container, list };
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
        if (term.length < 3) {
            // Empty/short term cancels any pending search rather than racing it.
            this._companySearchSeq += 1;
            this._abortPendingCompanySearch();
            responseCallback([]);
            return;
        }

        // Get country ISO from the selected option if available; otherwise omit
        const country = this.getCurrentCountry();

        // Build URL with correct API parameters. `limit`/`offset` mirror the
        // Magento and WooCommerce plugins: bound the response to one page so
        // a common name in a large country can't return an unbounded list.
        // Offset is always 0 - there is no load-more/next-page UI here, same
        // as select2's `pagination: { more: false }` on the other two
        // platforms.
        const limit = Number(this.config.companySearchLimit)
            || TwoCompanySearch.DEFAULT_COMPANY_SEARCH_LIMIT;
        const params = new URLSearchParams({ q: term, limit: limit, offset: 0 });
        if (country) params.set('country', country);
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
            timeout: 10000,
            success: (data) => {
                if (seq !== this._companySearchSeq) {
                    // Stale response for a superseded request; discard.
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

                responseCallback(formattedResults);
            },
            error: (xhr, status, error) => {
                if (seq !== this._companySearchSeq) {
                    // Aborted (superseded) or otherwise stale; nothing to report.
                    return;
                }
                this._companySearchXhr = null;

                responseCallback([]);
            }
        });
    }
    
    /**
     * Get current country from checkout form - SIMPLIFIED
     */
    getCurrentCountry() {
        // Method 1: Check if country option has data attribute with ISO code
        const countryField = document.querySelector("select[name='id_country']");
        if (countryField && countryField.selectedOptions.length > 0) {
            const selectedOption = countryField.selectedOptions[0];
            
            // Check for data-iso attribute
            const isoCode = selectedOption.getAttribute('data-iso-code') || selectedOption.getAttribute('data-iso');
            if (isoCode) {
                return isoCode.toUpperCase();
            }
            
            // Method 2: Extract from option text
            const optionText = selectedOption.textContent.trim();
            const countryFromText = this.extractCountryFromText(optionText);
            if (countryFromText) {
                return countryFromText;
            }
            
            // Method 3: Simple ID-based mapping for common countries
            const countryId = countryField.value;
            const commonCountries = {
                '17': 'GB', '6': 'ES', '8': 'FR', '1': 'DE', '10': 'IT',
                '13': 'NL', '3': 'BE', '21': 'US', '4': 'CA', '24': 'AU'
            };
            
            if (commonCountries[countryId]) {
                return commonCountries[countryId];
            }
        }
        
        // Method 4: Browser locale fallback
        const browserLang = navigator.language || navigator.userLanguage;
        if (browserLang && browserLang.includes('-')) {
            const countryCode = browserLang.split('-')[1];
            if (countryCode && countryCode.length === 2) {
                return countryCode.toUpperCase();
            }
        }
        
        return 'GB'; // Safe default
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
            
            // Persist for reliability across steps
            this.persistCompanyToCookie({
                company: ui.item.value,
                companyid: ui.item.organization_number
            });
            
            // Also sync to DNI field if it exists
            const dniField = $("input[name='dni']");
            if (dniField.length > 0) {
                dniField.val(ui.item.organization_number);
            }

            const vatField = $("input[name='vat_number']");
            if (vatField.length > 0) {
                vatField.val(ui.item.organization_number);
            }
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
                    const dniField = $("input[name='dni']");
                    if (dniField.length > 0) {
                        dniField.val(natIdVal);
                    }
                    const vatField = $("input[name='vat_number']");
                    if (vatField.length > 0) {
                        vatField.val(natIdVal);
                    }
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
            if (typeof value === 'undefined' || value === null) {
                return;
            }
            const field = $(`input[name='${fieldName}']`);
            if (field.length > 0) {
                if (field.val() !== String(value)) {
                    field.val(value);
                    field.trigger('input');
                    field.trigger('change');
                }
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
                // Recreate autocomplete to ensure new country is used immediately
                this.setupAutocomplete();
            };
            countryField.addEventListener('change', this.countryListener);
            this._boundCountrySelector = countryField;
        } else {
            
            // Log all select elements to help debugging
            
            
            // Try again after a delay (DOM might not be fully ready) - max 3 retries
            if (retryCount < 3) {
                setTimeout(() => {
                    this.setupCountryChangeListener(retryCount + 1);
                }, 1000);
            }
        }
        
        // Also listen for PrestaShop address form updates
        if (typeof prestashop !== 'undefined' && prestashop.on) {
            prestashop.on('updatedAddressForm', () => {
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
            if (countryField && this.countryListener) {
                countryField.removeEventListener('change', this.countryListener);
            }
            // Destroy autocomplete instance if present
            if (this.companyField && this.companyField.length && this.companyField.hasClass('ui-autocomplete-input')) {
                this.companyField.autocomplete('destroy');
            }
            // Remove custom autocomplete if present
            if (this._customAutocomplete) {
                if (this._customAutocomplete.list && this._customAutocomplete.list.parentNode) {
                    this._customAutocomplete.list.parentNode.removeChild(this._customAutocomplete.list);
                }
                if (this._customAutocomplete.container && this._customAutocomplete.container.parentNode) {
                    this._customAutocomplete.container.parentNode.removeChild(this._customAutocomplete.container);
                }
                this._customAutocomplete = null;
            }
        } catch (e) {
            // no-op
        }
        this.isInitialized = false;
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
