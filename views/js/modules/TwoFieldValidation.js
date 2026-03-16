/**
 * Two Field Validation Module - Handles conditional field requirements
 * Makes company field required for business accounts, optional for personal
 */
class TwoFieldValidation {
    constructor(config) {
        this.config = {
            accountTypeSelector: "select[name='account_type']",
            companyFieldSelector: "input[name='company']",
            ...config
        };
        
        this.accountTypeField = null;
        this.companyField = null;
        this.isInitialized = false;
        
        this.init();
    }
    
    /**
     * Initialize field validation
     */
    init() {
        this.accountTypeField = $(this.config.accountTypeSelector);
        this.companyField = $(this.config.companyFieldSelector);
        
        if (this.accountTypeField.length === 0 || this.companyField.length === 0) {
            return;
        }
        
        const useAccountType = !!(window.twopayment && String(window.twopayment.use_account_type) === '1');
        if (useAccountType) {
            this.setupValidationListeners();
            this.initializeCompanyFieldVisibility();
            this.updateCompanyFieldVisibility();
        } else {
            // Hide account type group entirely and relax company requirements
            const accountTypeGroup = this.accountTypeField.closest('.form-group, .form-field, .field-wrapper');
            if (accountTypeGroup && accountTypeGroup.length) {
                accountTypeGroup.hide();
            }
            this.companyField.removeAttr('required');
            this.companyField.attr('aria-required', 'false');
            this.clearCompanyFieldError();
        }
        this.isInitialized = true;
        
        // Field validation initialized
    }
    
    /**
     * Initialize company field visibility (hide by default)
     */
    initializeCompanyFieldVisibility() {
        const companyFieldGroup = this.companyField.closest('.form-group, .form-field, .field-wrapper');
        
        // Initially hide the company field
        companyFieldGroup.addClass('company-field-hidden');
        
        // Company field initially hidden
    }

    /**
     * Setup event listeners for validation
     */
    setupValidationListeners() {
        // Listen for account type changes
        this.accountTypeField.on('change', () => {
            this.updateCompanyFieldVisibility();
        });
        
        // Listen for form submission attempts
        this.companyField.closest('form').on('submit', (event) => {
            if (!this.validateCompanyField()) {
                event.preventDefault();
                this.showCompanyFieldError();
                return false;
            }
        });
        
        // Clear error when user starts typing in company field
        this.companyField.on('input', () => {
            this.clearCompanyFieldError();
        });
    }
    
    /**
     * Update company field visibility and requirement based on account type
     */
    updateCompanyFieldVisibility() {
        const accountType = this.accountTypeField.val();
        const isBusinessAccount = accountType === 'business';
        const companyFieldGroup = this.companyField.closest('.form-group, .form-field, .field-wrapper');
        
        if (isBusinessAccount) {
            // Show company field for business accounts
            companyFieldGroup.removeClass('company-field-hidden');
            this.companyField.attr('required', true);
            this.companyField.attr('aria-required', 'true');
            
            // Ensure field is enabled
            this.companyField.prop('disabled', false);
        } else {
            // Hide company field for personal accounts
            companyFieldGroup.addClass('company-field-hidden');
            this.companyField.removeAttr('required');
            this.companyField.attr('aria-required', 'false');
            this.clearCompanyFieldError();
            
            // Clear any existing value
            this.companyField.val('');
            
            // Also clear the hidden companyid field if it exists
            $("input[name='companyid']").val('');
        }
        
        // Company field visibility updated
    }
    
    /**
     * Validate company field based on current account type
     */
    validateCompanyField() {
        const accountType = this.accountTypeField.val();
        const isBusinessAccount = accountType === 'business';
        const companyValue = this.companyField.val().trim();
        
        if (isBusinessAccount && !companyValue) {
            return false;
        }
        
        return true;
    }
    
  
      
    /**
     * Get company field label element
     */
    getCompanyFieldLabel() {
        // Try multiple ways to find the label
        let label = $(`label[for="${this.companyField.attr('id')}"]`);
        
        if (label.length === 0) {
            label = this.companyField.closest('.form-group').find('label');
        }
        
        if (label.length === 0) {
            label = this.companyField.prev('label');
        }
        
        return label.length > 0 ? label : null;
    }
    
    /**
     * Show company field error
     */
    showCompanyFieldError() {
        this.clearCompanyFieldError();
        
        const errorMessage = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.company_name_required_business) ||
            'Company name is required for business accounts.';
        const errorElement = $(`<div class="alert alert-danger company-field-error" role="alert">${errorMessage}</div>`);
        
        // Insert error message after the company field
        this.companyField.after(errorElement);
        
        // Add error styling to field
        this.companyField.addClass('is-invalid');
        this.companyField.closest('.form-group').addClass('has-error');
        
        // Focus the field
        this.companyField.focus();
        
        // Scroll to field if needed
        $('html, body').animate({
            scrollTop: this.companyField.offset().top - 100
        }, 300);
    }
    
    /**
     * Clear company field error
     */
    clearCompanyFieldError() {
        $('.company-field-error').remove();
        this.companyField.removeClass('is-invalid');
        this.companyField.closest('.form-group').removeClass('has-error');
    }
    
    /**
     * Check if validation is ready
     */
    isReady() {
        return this.isInitialized && this.accountTypeField.length > 0 && this.companyField.length > 0;
    }
    
    /**
     * Get current validation state
     */
    getValidationState() {
        return {
            accountType: this.accountTypeField.val(),
            companyRequired: this.accountTypeField.val() === 'business',
            companyValue: this.companyField.val().trim(),
            isValid: this.validateCompanyField()
        };
    }
}

// Export for use in other modules
window.TwoFieldValidation = TwoFieldValidation;
