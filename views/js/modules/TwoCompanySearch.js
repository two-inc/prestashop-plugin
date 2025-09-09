/**
 * Two Company Search Module - Clean, focused company selection
 * Handles company autocomplete, organization number persistence, and address saving
 */
class TwoCompanySearch {
    constructor(config) {
        this.config = {
            companyFieldSelector: "input[name='company']",
            checkoutHost: '',
            saveCompanyUrl: '',
            ...config
        };
        
        this.companyField = null;
        this.organizationField = null;
        this.isInitialized = false;
        this.countryListener = null;
        
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
     * Setup jQuery UI Autocomplete for company search
     */
    setupAutocomplete() {
        if (!this.config.checkoutHost) {
            console.error('TwoCompanySearch: No checkout host configured');
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
                console.warn('TwoCompanySearch: jQuery UI present but autocomplete class missing');
            }
        } else {
            console.warn('TwoCompanySearch: jQuery UI autocomplete not available, using custom autocomplete');
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
                this.searchCompanies(term, (results) => {
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
     * Search for companies via Two API
     */
    searchCompanies(term, responseCallback) {
        if (term.length < 3) {
            responseCallback([]);
            return;
        }
        
        // Get country ISO from the selected option if available; otherwise omit
        const country = this.getCurrentCountry();
        
        // Build URL with correct API parameters
        const params = new URLSearchParams({ q: term });
        if (country) params.set('country', country);
        // Direct Two API call from frontend as required
        const searchUrl = `${this.config.checkoutHost}/companies/v2/company?${params}`;
        
        $.ajax({
            url: searchUrl,
            method: 'GET',
            dataType: 'json',
            timeout: 10000,
            success: (data) => {
                const companies = data.items || [];
                const formattedResults = companies.map(company => {
                    const orgNumber = company.national_identifier ? company.national_identifier.id : '';
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
                console.error('TwoCompanySearch: API request failed:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    url: searchUrl
                });
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
        
        // SIMPLE & RELIABLE: Direct field assignment like old tillit.js
        this.companyField.val(ui.item.value);
        
        // Set organization number immediately if available
        if (ui.item.organization_number) {
            this.organizationField.val(ui.item.organization_number);
            
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
        }
        
        // Optional: Fetch additional details for address auto-fill if lookup_id is available
        if (ui.item.lookup_id) {
            this.fetchCompanyDetails(ui.item.lookup_id)
                .then(details => {
                    this.autoFillAddressIfNeeded(details);
                })
                .catch(error => {
                    // Silently fail - address auto-fill is not critical
                });
        }

        // If user already selected Two payment, re-run order intent with new company
        try {
            if (window.TwoCheckoutManager_Instance && window.TwoCheckoutManager_Instance.isTwoPaymentSelected && window.TwoCheckoutManager_Instance.isTwoPaymentSelected()) {
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
                dataType: 'json',
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
            const natId = (details && (details.national_identifier || details.nationalIdentifier));
            const natIdVal = natId ? (natId.id || natId.value || natId.organisationNumber || natId.organizationNumber) : null;
            if (natIdVal) {
                const currentOrgNumber = this.organizationField.val();
                if (!currentOrgNumber || currentOrgNumber !== natIdVal) {
                    this.organizationField.val(natIdVal);
                    const dniField = $("input[name='dni']");
                    if (dniField.length > 0) {
                        dniField.val(natIdVal);
                    }
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
        if (this.organizationField) this.organizationField.val('');
    }
    
    /**
     * Store company data in session storage for persistence across checkout steps
     */
    storeCompanyDataInSession(companyData) {
        try {
            sessionStorage.setItem('two_company_data', JSON.stringify(companyData));
            // Company data stored in session storage
        } catch (e) {
            console.error('TwoCompanySearch: Failed to store company data in session:', e);
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
                console.log('TwoCompanySearch: Found country field with selector:', selector);
                break;
            }
        }
        
        if (countryField) {
            if (this.countryListener) {
                countryField.removeEventListener('change', this.countryListener);
            }
            this.countryListener = () => {
                console.log('TwoCompanySearch: Country changed, clearing company fields');
                // Clear any existing autocomplete cache
                if (this.companyField.hasClass('ui-autocomplete-input')) {
                    this.companyField.autocomplete('close');
                    // Clear the company field when country changes as it may no longer be valid
                    this.companyField.val('');
                    if (this.organizationField) {
                        this.organizationField.val('');
                    }
                }
            };
            countryField.addEventListener('change', this.countryListener);
        } else {
            console.warn('TwoCompanySearch: No country field found with any selector, trying delayed setup');
            // Log all select elements to help debugging
            const allSelects = document.querySelectorAll('select');
            console.log('TwoCompanySearch: All select elements found:', Array.from(allSelects).map(s => s.name || s.id || s.className));
            
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
                // Clear company fields when address form is updated
                if (this.companyField.length > 0) {
                    this.companyField.val('');
                    if (this.organizationField) {
                        this.organizationField.val('');
                    }
                }
            });
        }
    }

    destroy() {
        try {
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
                    companyid: data.companyid
                },
                timeout: 10000
            });
        } catch (e) {
            // no-op
        }
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
