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
        
        // Ensure jQuery UI autocomplete is available
        if (typeof $.fn.autocomplete !== 'function') {
            console.error('TwoCompanySearch: jQuery UI autocomplete not available, retrying...');
            setTimeout(() => this.setupAutocomplete(), 500);
            return;
        }
        
        this.companyField.autocomplete({
            source: (request, response) => {
                this.searchCompanies(request.term, response);
            },
            minLength: 3,
            delay: 300,
            select: (event, ui) => {
                return this.onCompanySelected(event, ui);
            }
        });
        
        // Test if autocomplete was properly initialized
        if (!this.companyField.hasClass('ui-autocomplete-input')) {
            console.error('TwoCompanySearch: Autocomplete initialization failed');
        }
    }
    
    /**
     * Search for companies via Two API
     */
    searchCompanies(term, responseCallback) {
        if (term.length < 3) {
            responseCallback([]);
            return;
        }
        
        // Get country from checkout form
        const country = this.getCurrentCountry() || 'GB';
        
        // Build URL with correct API parameters
        const params = new URLSearchParams({
            q: term,
            country: country
        });
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
            
            // Store in session storage for persistence across checkout steps
            this.storeCompanyDataInSession({
                company: ui.item.value,
                companyid: ui.item.organization_number,
                lookup_id: ui.item.lookup_id,
                timestamp: Date.now()
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
        
        return true;
    }
    
    /**
     * Fetch detailed company information
     */
    fetchCompanyDetails(lookupId) {
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
        // Update organization number if we have a more authoritative one
        if (details.national_identifier && details.national_identifier.id) {
            const currentOrgNumber = this.organizationField.val();
            if (!currentOrgNumber || currentOrgNumber !== details.national_identifier.id) {
                this.organizationField.val(details.national_identifier.id);
                
                // Also sync to DNI field if it exists
                const dniField = $("input[name='dni']");
                if (dniField.length > 0) {
                    dniField.val(details.national_identifier.id);
                }
            }
        }
        
        // Auto-fill address fields if available and empty
        if (details.addresses && details.addresses.length > 0) {
            this.autoFillAddress(details.addresses);
        }
    }
    
    
    /**
     * Auto-fill address fields with company address data
     */
    autoFillAddress(addresses) {
        // Find business address or use first address
        const address = addresses.find(addr => addr.type === 'BUSINESS_ADDRESS') || addresses[0];
        
        if (!address) return;
        
        const fieldMappings = {
            'address1': address.street_address,
            'postcode': address.postal_code,
            'city': address.city
        };
        
        Object.entries(fieldMappings).forEach(([fieldName, value]) => {
            if (value) {
                const field = $(`input[name='${fieldName}']`);
                if (field.length > 0 && !field.val()) {
                    field.val(value);
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
        try {
            const stored = sessionStorage.getItem('two_company_data');
            if (stored) {
                const data = JSON.parse(stored);
                // Check if data is not too old (30 minutes)
                if (Date.now() - data.timestamp < 1800000) {
                    return data;
                }
            }
        } catch (e) {
            console.error('TwoCompanySearch: Error retrieving company data from session:', e);
        }
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
            countryField.addEventListener('change', () => {
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
            });
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

    /**
     * Check if module is available and initialized
     */
    isReady() {
        return this.isInitialized && this.companyField && this.companyField.length > 0;
    }
}

// Export for use in other modules
window.TwoCompanySearch = TwoCompanySearch;
