/**
 * Two Checkout Manager - IMPROVED with PrestaShop-native patterns
 * Theme-independent, using native PrestaShop events and patterns
 */
class TwoCheckoutManager {
    constructor(config) {
        this.config = {
            companySearchEnabled: false,
            orderIntentEnabled: false,
            checkoutHost: '',
            orderIntentUrl: '',
            ajaxToken: '',
            ...config
        };
        
        
        
        this.companySearch = null;
        this.orderIntent = null;
        this.fieldValidation = null;
        this.currentStep = 'unknown';
        this.isBusinessAccount = false;
        this.isInitialized = false;
        this.twoPaymentOption = null;
        this.isLoadingUIShown = false;
        this._intentCooldownMs = 800;
        this._lastIntentRunAt = 0;
        this._initialIntentTriggered = false;
        
        this.init();
    }
    
    /**
     * Initialize the checkout manager
     */
    init() {
        // Detect initial checkout context using PrestaShop-native methods
        this.detectCheckoutStep();
        this.detectAccountType();
        
        // Initialize modules based on configuration and context
        this.initializeModules();
        
        // Setup PrestaShop-native event listeners
        this.setupPrestaShopEventListeners();
        
        this.isInitialized = true;
    }
    
    /**
     * IMPROVED: Detect checkout step using PrestaShop-native patterns
     */
    detectCheckoutStep() {
        // Method 1: Check URL path (most reliable for PrestaShop)
        const currentPath = window.location.pathname;
        if (currentPath.includes('/order')) {
            const urlParams = new URLSearchParams(window.location.search);
            const step = urlParams.get('step');
            if (step) {
                this.currentStep = step;
                return;
            }
        }
        
        // Method 2: Check PrestaShop body classes (theme-independent)
        const bodyClasses = document.body.className;
        
        // Check for PrestaShop controller classes
        const controllerMatch = bodyClasses.match(/controller-(\w+)/);
        if (controllerMatch && controllerMatch[1] === 'order') {
            // We're in order controller - detect sub-step by content
            if (document.querySelector('.payment-options')) {
                this.currentStep = 'payment';
            } else if (document.querySelector('.js-address-form, form[name*="address"], form[name*="customer"]')) {
                this.currentStep = 'address';
            } else {
                this.currentStep = 'checkout';
            }
            return;
        }
        
        // Method 3: Check for one-page checkout
        if (bodyClasses.includes('order-opc') || bodyClasses.includes('checkout')) {
            if (document.querySelector('.payment-options')) {
                this.currentStep = 'payment';
            } else {
                this.currentStep = 'address';
            }
            return;
        }
        
        // Fallback: Basic content detection (still theme-independent)
        if (document.querySelector('.payment-options')) {
            this.currentStep = 'payment';
        } else if (document.querySelector('form[name*="address"], .js-address-form')) {
            this.currentStep = 'address';
        } else {
            this.currentStep = 'unknown';
        }
    }
    
    /**
     * SIMPLIFIED: Account type detection using PrestaShop payment option visibility
     */
    detectAccountType() {
        // SIMPLE & RELIABLE: If Two payment option is visible, PrestaShop determined it's a business account
        this.twoPaymentOption = document.querySelector('[data-module-name="twopayment"]');

        // When account type is disabled, treat Two as available regardless of business/personal at address step
        const useAccountType = !!(window.twopayment && String(window.twopayment.use_account_type) === '1');
        if (!useAccountType) {
            this.isBusinessAccount = true; // allow company search & Two flow; we will gate order intent later by company presence
        } else {
            this.isBusinessAccount = !!this.twoPaymentOption;
        }

        // Fallback for address step: use account_type select value
        if (!this.isBusinessAccount) {
            const accountTypeField = document.querySelector("select[name='account_type']");
            if (accountTypeField) {
                this.isBusinessAccount = accountTypeField.value === 'business';
            }
        }
        
        // Also store reference to payment radio for easy access
        if (this.twoPaymentOption) {
            this.twoPaymentRadio = this.twoPaymentOption.querySelector('input[type="radio"]');
        }
    }
    
    /**
     * Setup PrestaShop-native event listeners
     */
    setupPrestaShopEventListeners() {
        // Listen for PrestaShop's native events (theme-independent)
        if (typeof prestashop !== 'undefined') {
            // Address form updates
            prestashop.on('updatedAddressForm', () => {
                this.handleAddressFormUpdate();
            });
            
            // Delivery form updates  
            prestashop.on('updatedDeliveryForm', () => {
                this.handleDeliveryFormUpdate();
            });
            
            // Payment form updates
            prestashop.on('updatedPaymentForm', () => {
                this.handlePaymentFormUpdate();
            });
            
            // Checkout updates
            prestashop.on('checkout', (event) => {
                this.handleCheckoutEvent(event);
            });
        }
        
        // CRITICAL: Listen for payment option selection (theme-independent)
        this.setupPaymentOptionSelectionListener();
        
        // Listen for account type changes to re-init company search
        this.setupAccountTypeChangeListener();

        // Listen for DOM mutations for dynamic content
        this.setupMutationObserver();
    }

    setupAccountTypeChangeListener() {
        if (this._accountTypeListenerAdded) return;
        const accountTypeField = document.querySelector("select[name='account_type']");
        if (!accountTypeField) return;
        this._accountTypeListenerAdded = true;
        accountTypeField.addEventListener('change', () => {
            const value = accountTypeField.value;
            const wasBusiness = this.isBusinessAccount;
            this.isBusinessAccount = (value === 'business');
            try { sessionStorage.setItem('two_account_type', value); } catch (e) {}
            // Re-init company search accordingly
            if (this.config.companySearchEnabled) {
                if (this.isBusinessAccount && !this.companySearch) {
                    this.initializeCompanySearch();
                } else if (!this.isBusinessAccount && this.companySearch && this.companySearch.destroy) {
                    this.companySearch.destroy();
                    this.companySearch = null;
                }
            }
        });
    }
    
    /**
     * CRITICAL: Only trigger order intent when Two payment is selected
     */
    setupPaymentOptionSelectionListener() {
        // Method 1: Listen for payment radio button changes (theme-independent)
        document.addEventListener('change', (event) => {
            // Check various PrestaShop payment radio patterns
            if (event.target.matches('input[type="radio"][name*="payment"], input[name="payment-option"]')) {
                this.handlePaymentOptionChange(event.target);
            }
        });
        
        // Method 2: Listen for clicks on payment option containers (theme-independent)
        document.addEventListener('click', (event) => {
            const paymentOption = event.target.closest('[data-module-name="twopayment"]');
            if (paymentOption) {
                this.handleTwoPaymentSelection();
            }
        });
        
        // Method 3: Listen for payment confirmation attempts
        document.addEventListener('click', (event) => {
            // Various PrestaShop confirmation button patterns
            if (event.target.matches([
                '#payment-confirmation button',
                '.payment-confirmation button', 
                'button[name="confirmDeliveryOption"]',
                'button[type="submit"][form*="payment"]'
            ].join(', '))) {
                this.handlePaymentConfirmation(event);
            }
        });
    }
    
    /**
     * Handle payment option change - trigger order intent only for Two
     */
    handlePaymentOptionChange(radioButton) {
        // Check if Two payment is selected using various patterns
        const isTwoSelected = this.isTwoPaymentSelected(radioButton);
        
        if (isTwoSelected && this.config.orderIntentEnabled) {
            // Ensure orderIntent is initialized even after dynamic DOM changes
            if (!this.orderIntent && window.TwoOrderIntent) {
                this.initializeOrderIntent();
            }
            if (this.orderIntent) {
                // Two payment selected - trigger order intent validation
                this.triggerOrderIntentForSelection();
            }
        } else if (this.orderIntent) {
            // Different payment selected - clear any Two-specific UI
            this.clearOrderIntentUI();
        }
    }
    
    /**
     * Check if Two payment is selected (theme-independent)
     */
    isTwoPaymentSelected(radioButton) {
        // Prefer currently checked radio
        if (!radioButton) {
            radioButton = document.querySelector('input[type="radio"][name="payment-option"]:checked') ||
                           document.querySelector('input[type="radio"][name*="payment"]:checked');
        }
        if (!radioButton) return false;

        // Locate the Two payment option container
        const twoOption = this.twoPaymentOption || document.querySelector('[data-module-name="twopayment"]');
        if (twoOption) {
            return twoOption.contains(radioButton);
        }

        // Fallback heuristics
        if (radioButton.value === 'twopayment' || radioButton.id === 'payment-option-twopayment') {
            return true;
        }
        return false;
    }
    
    /**
     * Handle Two payment selection specifically
     */
    handleTwoPaymentSelection() {
        if (this.config.orderIntentEnabled) {
            if (!this.orderIntent && window.TwoOrderIntent) {
                this.initializeOrderIntent();
            }
            if (this.orderIntent) {
                this.triggerOrderIntentForSelection();
            }
        }
    }
    
    /**
     * Trigger order intent specifically for payment selection
     */
    triggerOrderIntentForSelection() {
        // Only proceed if we're in payment step and Two is available
        if (this.currentStep !== 'payment' || !this.twoPaymentOption) {
            return;
        }
        
        // Only proceed if Two is actually selected
        if (!this.isTwoPaymentSelected()) {
            return;
        }
        
        // Prevent duplicate or rapid calls
        if (this.orderIntent && this.orderIntent.isProcessing) {
            this.showOrderIntentLoading();
            return;
        }
        const now = Date.now();
        if (now - this._lastIntentRunAt < this._intentCooldownMs) {
            return;
        }
        this._lastIntentRunAt = now;

        // Show loading state
        this.showOrderIntentLoading();

        // If order intent isn't ready to run yet, keep showing loading and retry shortly
        if (this.orderIntent && typeof this.orderIntent.shouldRunOrderIntent === 'function' && !this.orderIntent.shouldRunOrderIntent()) {
            clearTimeout(this._intentRetryTimeout);
            this._intentRetryTimeout = setTimeout(() => {
                this.triggerOrderIntentForSelection();
            }, 400);
            return;
        }

        this.orderIntent.checkOrderIntent().then(result => {
            this.handleOrderIntentResult(result);
        }).catch(error => {
            this.handleOrderIntentError(error.message || 'Order intent check failed');
        });
    }
    
    /**
     * Handle order intent result and update UI accordingly
     */
    handleOrderIntentResult(result) {
        if (!result.success) {
            // If order intent was skipped (e.g., missing company data), show a gentle prompt when account type is disabled
            const err = (result && result.error) ? String(result.error).toLowerCase() : '';
            const useAccountType = !!(window.twopayment && String(window.twopayment.use_account_type) === '1');
            if (err.includes('skipped') && !useAccountType) {
                const messageContainer = this.getOrCreateMessageContainer();
                const requiredMsg = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.select_company_to_use_two) || 'To pay with Two, please select your company from the search results so we can verify your business and offer invoice terms.';
                const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
                if (messageElement !== messageContainer) {
                    messageElement.innerHTML = requiredMsg;
                } else {
                    messageContainer.innerHTML = `
                        <p class="two-subtitle">${(window.twopayment && window.twopayment.i18n && window.twopayment.i18n.action_required_title) || 'Action Required'}</p>
                        <p class="two-payment-message">${requiredMsg}</p>
                    `;
                }
                messageContainer.classList.remove('approved', 'loading');
                messageContainer.classList.add('show');
                messageContainer.style.display = 'block';
                this.hideLoadingOverlay();
                return;
            }
            this.showOrderIntentError(result.error || 'Order intent check failed');
            return;
        }

        // Build company-aware message for display (translated)
        const companyName = this.getSelectedCompanyName();
        if (result.approved) {
            let approvedMsg = result.message || ((window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_approved_message) || 'Payment approved! Choose your payment terms below.');
            if (companyName && window.twopayment && window.twopayment.i18n && window.twopayment.i18n.invoice_likely_accepted_for) {
                approvedMsg = window.twopayment.i18n.invoice_likely_accepted_for.replace('%s', companyName);
            }
            this.showOrderIntentApproval(approvedMsg);
        } else {
            // For declined results, also check if the decline reason should be treated as an error
            const baseDecline = result.message || ((window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_not_available_message) || 'Two payment is not available for this order.');
            let declineMessage = baseDecline;
            if (companyName && window.twopayment && window.twopayment.i18n && window.twopayment.i18n.invoice_cannot_be_approved_for) {
                declineMessage = window.twopayment.i18n.invoice_cannot_be_approved_for.replace('%s', companyName);
            }
            if (this.isDeclineReasonAnError(baseDecline)) {
                this.showOrderIntentError(declineMessage);
            } else {
                this.showOrderIntentDecline(declineMessage);
                this.disableTwoPayment();
            }
        }
    }

    /**
     * Get selected company name from latest intent state or input field
     */
    getSelectedCompanyName() {
        try {
            if (this.orderIntent && this.orderIntent.lastCompany) {
                return this.orderIntent.lastCompany;
            }
            const companyField = document.querySelector("input[name='company']");
            if (companyField && companyField.value && companyField.value.trim().length > 0) {
                return companyField.value.trim();
            }
        } catch (e) {}
        return '';
    }

    /**
     * Check if a decline reason should be treated as an error requiring user action
     */
    isDeclineReasonAnError(message) {
        if (typeof message !== 'string') return false;
        
        const messageLower = message.toLowerCase();
        
        // These are user-actionable errors, not just payment declines
        return messageLower.includes('company name is required') ||
               messageLower.includes('organization number') ||
               messageLower.includes('invalid company') ||
               messageLower.includes('company information') ||
               messageLower.includes('please enter') ||
               messageLower.includes('please provide') ||
               messageLower.includes('missing');
    }
    
    /**
     * Show order intent loading state (theme-independent)
     */
    showOrderIntentLoading() {
        if (!this.twoPaymentOption) return;
        const parent = this.twoPaymentOption.querySelector('.payment-option-content') || this.twoPaymentOption;
        parent.classList.add('two-overlay-parent');
        let overlay = parent.querySelector('#two-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'two-loading-overlay';
            overlay.className = 'two-loading-overlay';
            overlay.innerHTML = `
                <div class="two-loading-container">
                    <div class="two-loading-spinner"></div>
                    <span class="two-loading-text">${(window.twopayment && window.twopayment.i18n && window.twopayment.i18n.checking_eligibility) || 'Checking Two payment eligibility...'}</span>
                </div>
            `;
            parent.appendChild(overlay);
        }
        overlay.classList.add('show');
        this.isLoadingUIShown = true;

        // Also show template loader if present (for themes using paymentinfo.tpl container)
        try {
            const templateLoader = document.querySelector('.two-payment-container .two-loading-container');
            if (templateLoader) {
                templateLoader.style.display = 'flex';
            }
        } catch (e) { /* noop */ }
    }

    /**
     * Show order intent approval message and payment terms (theme-independent)
     */
    showOrderIntentApproval(message) {
        
        const messageContainer = this.getOrCreateMessageContainer();
        
        // Update the payment info section with success message
        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.innerHTML = message || ((window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_approved_message) || 'Payment approved! Choose your payment terms below.');
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">${(window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_approved_title) || 'Payment Approved'}</p>
                <p class="two-payment-message">${message || ((window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_approved_message) || 'Payment approved! Choose your payment terms below.')}</p>
            `;
        }
        
        // Add approved styling to container
        messageContainer.classList.remove('declined', 'loading');
        messageContainer.classList.add('approved', 'show');
        messageContainer.style.display = 'block';
        
        // Remove loading state and add approved state to payment option
        this.clearLoadingState();
        this.hideLoadingOverlay();
        
        // Show payment terms selector
        
        this.showPaymentTerms();
    }
    
    /**
     * Show order intent decline message (theme-independent)
     */
    showOrderIntentDecline(message) {
        const messageContainer = this.getOrCreateMessageContainer();
        
        // Update the payment info section with decline message
        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.innerHTML = message || ((window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_not_available_message) || 'Two payment is not available for this order.');
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">${(window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_not_available_title) || 'Payment Not Available'}</p>
                <p class="two-payment-message">${message || ((window.twopayment && window.twopayment.i18n && window.twopayment.i18n.payment_not_available_message) || 'Two payment is not available for this order.')}</p>
            `;
        }
        
        // Add declined styling to container
        messageContainer.classList.remove('approved', 'loading');
        messageContainer.classList.add('declined', 'show');
        messageContainer.style.display = 'block';
        
        // Remove loading state and add declined state to payment option
        this.clearLoadingState();
        this.hideLoadingOverlay();
    }
    
    /**
     * Show order intent error (theme-independent)
     */
    showOrderIntentError(error) {
        // Convert technical error messages to user-friendly ones
        const userFriendlyError = this.getUserFriendlyErrorMessage(error);
        
        const messageContainer = this.getOrCreateMessageContainer();
        
        // Update the payment info section with error message
        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.innerHTML = userFriendlyError;
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">${(window.twopayment && window.twopayment.i18n && window.twopayment.i18n.action_required_title) || 'Action Required'}</p>
                <p class="two-payment-message">${userFriendlyError}</p>
            `;
        }
        
        // Add error styling to container
        messageContainer.classList.remove('approved', 'loading', 'declined');
        messageContainer.classList.add('show');
        messageContainer.style.display = 'block';
        
        // Remove loading state
        this.clearLoadingState();
        this.isLoadingUIShown = false;
    }

    /**
     * Convert technical error messages to user-friendly ones
     */
    getUserFriendlyErrorMessage(error) {
        // Handle specific error cases
        if (typeof error === 'string') {
            // Case: "Company name is required for business accounts"
            if (error.toLowerCase().includes('company name is required')) {
                return 'Please enter your company name to continue with Two payment.';
            }
            
            // Case: "Organization number is required"
            if (error.toLowerCase().includes('organization number') && error.toLowerCase().includes('required')) {
                return 'Please search and select a valid company to continue with Two payment.';
            }
            
            // Case: "Invalid company information"
            if (error.toLowerCase().includes('invalid company')) {
                return 'The company information provided is not valid. Please search and select a valid company.';
            }
            
            // Case: "Company not found"
            if (error.toLowerCase().includes('company not found')) {
                return 'We could not find your company in our database. Please try searching with a different company name or contact support.';
            }
            
            // Case: "Credit check failed" or similar
            if (error.toLowerCase().includes('credit') || error.toLowerCase().includes('not approved')) {
                return 'Two payment is not available for this order. Please choose another payment method.';
            }
            
            // Case: API or network errors
            if (error.toLowerCase().includes('network') || error.toLowerCase().includes('timeout') || error.toLowerCase().includes('api')) {
                return 'There was a temporary issue verifying your payment. Please try again or choose another payment method.';
            }
        }
        
        // Default fallback for unknown errors
        return 'There was an issue processing your Two payment request. Please try again or choose another payment method.';
    }
    
    /**
     * Clear loading state from payment option
     */
    clearLoadingState() {
        // No-op: we no longer toggle visual state classes on the payment option
    }

    hideLoadingOverlay() {
        this.isLoadingUIShown = false;
        if (!this.twoPaymentOption) return;
        const parent = this.twoPaymentOption.querySelector('.payment-option-content') || this.twoPaymentOption;
        const overlay = parent.querySelector('#two-loading-overlay');
        if (overlay) {
            overlay.classList.remove('show');
        }

        // Hide template loader if present
        try {
            const templateLoader = document.querySelector('.two-payment-container .two-loading-container');
            if (templateLoader) {
                templateLoader.style.display = 'none';
            }
        } catch (e) { /* noop */ }
    }

    /**
     * Clear order intent UI
     */
    clearOrderIntentUI() {
        const messageContainer = document.querySelector('#two-order-intent-messages');
        if (messageContainer) {
            messageContainer.style.display = 'none';
            messageContainer.innerHTML = '';
        }
        
        // Also clear payment terms
        this.hidePaymentTerms();
        
        // Also clear visual states from payment option
        this.clearLoadingState();
    }

    /**
     * Hide payment terms selector
     */
    hidePaymentTerms() {
        const termsContainer = document.querySelector('#two-payment-terms');
        if (termsContainer) {
            termsContainer.classList.remove('show');
            // Use timeout to allow animation to complete before hiding
            setTimeout(() => {
                termsContainer.style.display = 'none';
            }, 300);
        }
    }
    
    /**
     * Get or create message container for order intent feedback (uses existing payment card structure)
     */
    getOrCreateMessageContainer() {
        // First try to use the existing payment info section from the template
        let container = document.querySelector('.two-payment-info');
        
        if (container) {
            // Use existing payment info section and make it visible
            container.style.display = 'block';
            container.classList.add('show');
            return container;
        }
        
        // Fallback: create new container if template structure not found
        container = document.querySelector('#two-order-intent-messages');
        if (!container) {
            container = document.createElement('div');
            container.id = 'two-order-intent-messages';
            container.className = 'two-order-intent-messages';
            container.style.marginTop = '10px';
            
            // Insert in Two payment container using theme-independent method
            const twoContainer = document.querySelector('.two-payment-container');
            if (twoContainer) {
                twoContainer.appendChild(container);
            } else if (this.twoPaymentOption) {
                // Fallback to payment option insertion
                const insertionPoint = this.twoPaymentOption.querySelector('.payment-option-content') || 
                                     this.twoPaymentOption.querySelector('.additional-information') ||
                                     this.twoPaymentOption;
                
                if (insertionPoint === this.twoPaymentOption) {
                    insertionPoint.appendChild(container);
                } else {
                    insertionPoint.appendChild(container);
                }
            }
        }
        return container;
    }
    
    /**
     * Show payment terms selector after approval
     */
    showPaymentTerms() {
        const termsContainer = document.querySelector('#two-payment-terms');
        if (termsContainer) {
            // First make it visible, then add animation class
            termsContainer.style.display = 'block';
            termsContainer.style.visibility = 'visible';
            
            // Force a reflow before adding the show class for animation
            termsContainer.offsetHeight;
            termsContainer.classList.add('show');
            
            // Initialize payment terms if not already done
            this.initializePaymentTerms();
            
        } else {
            
            // Try to find it in the entire document
            const allTermsElements = document.querySelectorAll('[id*="payment-terms"], [class*="payment-terms"]');
            
        }
    }

    /**
     * Initialize payment terms selector with available terms
     */
    initializePaymentTerms() {
        const termsSlider = document.querySelector('#two-terms-slider');
        const selectedDays = document.querySelector('#two-selected-days');
        
        
        if (!termsSlider) {
            
            return;
        }
        
        if (termsSlider.hasChildNodes()) {
            
            // Clear existing content to reinitialize
            termsSlider.innerHTML = '';
        }
        
        // Get payment terms from admin configuration (passed via template)
        const availableTerms = this.config.available_payment_terms;
        const defaultTerm = this.config.default_payment_term;
        
        
        // If no terms configured, don't show payment terms
        if (!availableTerms || !Array.isArray(availableTerms) || availableTerms.length === 0) {
            
            return;
        }
        
        // Create term options
        availableTerms.forEach((days, index) => {
            const termOption = document.createElement('div');
            termOption.className = 'two-term-option';
            termOption.textContent = days;
            termOption.dataset.days = days;
            
            // Set default term: use configured default, or if only one term, make it active, or first term
            const isDefaultTerm = defaultTerm ? (days === defaultTerm) : 
                                 (availableTerms.length === 1 ? true : index === 0);
            
            if (isDefaultTerm) {
                termOption.classList.add('active');
            }
            
            termOption.addEventListener('click', () => {
                // Remove active class from all options
                termsSlider.querySelectorAll('.two-term-option').forEach(opt => {
                    opt.classList.remove('active');
                });
                
                // Add active class to selected option
                termOption.classList.add('active');
                
                // Update selected days display
                if (selectedDays) {
                    selectedDays.textContent = days;
                }

                // Persist selection in cookie via backend (10s timeout)
                try {
                    if (window.twopayment && window.twopayment.order_intent_url && window.twopayment.ajax_token) {
                        $.ajax({
                            url: window.twopayment.order_intent_url,
                            type: 'POST',
                            dataType: 'json',
                            data: { ajax: 1, action: 'savePaymentTerm', token: window.twopayment.ajax_token, days: days },
                            timeout: 10000
                        });
                    }
                } catch (e) { /* noop */ }
            });
            
            termsSlider.appendChild(termOption);
        });
        
        // Set initial selected days based on the active term
        if (selectedDays) {
            const activeTerm = defaultTerm || (availableTerms.length === 1 ? availableTerms[0] : availableTerms[0]);
            selectedDays.textContent = activeTerm;
        }
    }

    /**
     * Disable Two payment option (theme-independent)
     */
    disableTwoPayment() {
        // Keep functionality minimal: avoid adding custom styles/classes to payment option
        if (this.twoPaymentRadio) {
            this.twoPaymentRadio.disabled = true;
        }
    }
    
    /**
     * Setup mutation observer for dynamic content (theme-independent)
     */
    setupMutationObserver() {
        const observer = new MutationObserver((mutations) => {
            let shouldReinitialize = false;
            
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList') {
                    // Check if payment options were added/changed
                    const addedNodes = Array.from(mutation.addedNodes);
                    const hasPaymentChanges = addedNodes.some(node => 
                        node.nodeType === 1 && (
                            (node.matches && node.matches('.payment-options, [data-module-name="twopayment"]')) ||
                            (node.querySelector && node.querySelector('.payment-options, [data-module-name="twopayment"]'))
                        )
                    );
                    
                    if (hasPaymentChanges) {
                        shouldReinitialize = true;
                    }
                }
            });
            
            if (shouldReinitialize) {
                // Debounce reinitializations
                clearTimeout(this.reinitializeTimeout);
                this.reinitializeTimeout = setTimeout(() => {
                    this.handleDynamicContentChange();
                }, 100);
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    /**
     * Handle dynamic content changes (theme-independent)
     */
    handleDynamicContentChange() {
        // Re-detect everything
        this.detectCheckoutStep();
        this.detectAccountType();
        
        // Re-setup payment listeners
        this.setupPaymentOptionSelectionListener();
        
        // Initialize modules if needed
        if (this.isBusinessAccount && this.config.orderIntentEnabled && !this.orderIntent) {
            this.initializeOrderIntent();
        }
    }
    
    /**
     * Handle PrestaShop address form updates
     */
    handleAddressFormUpdate() {
        // Re-detect context
        this.detectCheckoutStep();
        this.detectAccountType();

        // Re-initialize company search when address form updates
        if (this.config.companySearchEnabled) {
            // Attach fresh listener to new select element after DOM replacement
            this._accountTypeListenerAdded = false;
            this.setupAccountTypeChangeListener();

            // Restore previously selected account type if we have it
            try {
                const saved = sessionStorage.getItem('two_account_type');
                const accountTypeField = document.querySelector("select[name='account_type']");
                if (saved && accountTypeField && accountTypeField.value !== saved) {
                    accountTypeField.value = saved;
                    accountTypeField.dispatchEvent(new Event('change', { bubbles: true }));
                    this.isBusinessAccount = (saved === 'business');
                }
            } catch (e) {}

            if (this.companySearch && this.companySearch.destroy) {
                this.companySearch.destroy();
                this.companySearch = null;
            }
            if (this.isBusinessAccount) {
                this.initializeCompanySearch();
            }
            // Clear cached intent state when address is edited so a new selection can trigger intent
            if (this.orderIntent && this.orderIntent.reset) {
                this.orderIntent.reset();
            }
        }

        // Initialize phone validation on updated form
        if (window.TwoPhoneValidation) {
            try { new TwoPhoneValidation(); } catch (e) {}
        }
    }
    
    /**
     * Handle PrestaShop delivery form updates  
     */
    handleDeliveryFormUpdate() {
        // Update step detection as delivery affects checkout flow
        this.detectCheckoutStep();
    }
    
    /**
     * Handle PrestaShop payment form updates
     */
    handlePaymentFormUpdate() {
        this.detectAccountType();
        this.handleDynamicContentChange();
        // If Two is available and selected after payment form refresh, ensure order intent runs
        if (this.config.orderIntentEnabled) {
            if (!this.orderIntent && window.TwoOrderIntent) {
                this.initializeOrderIntent();
            }
            if (this.orderIntent && this.isTwoPaymentSelected()) {
                this.triggerOrderIntentForSelection();
            }
        }
    }
    
    /**
     * Handle PrestaShop checkout events
     */
    handleCheckoutEvent(event) {
        // React to various checkout events
        if (event && event.eventType) {
            switch (event.eventType) {
                case 'updateCart':
                case 'updateDelivery':
                    this.detectCheckoutStep();
                    break;
                case 'updatePayment':
                    this.detectAccountType();
                    break;
            }
        }
    }
    
    /**
     * Handle payment confirmation
     */
    handlePaymentConfirmation(event) {
        if (this.isTwoPaymentSelected() && this.orderIntent && this.config.orderIntentEnabled) {
            // If processing or no result yet, block and show loading
            if (this.orderIntent.isProcessing || !this.orderIntent.lastResult) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                this.showOrderIntentLoading();
                this.triggerOrderIntentForSelection();
                return;
            }
            // If declined, block with message
            if (this.orderIntent.lastResult && !this.orderIntent.lastResult.approved) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                const msg = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.approval_required) || 'Payment approval required before proceeding';
                this.showOrderIntentError(msg);
            }
        }
    }
    
    /**
     * Initialize modules based on context
     */
    initializeModules() {
        // Always initialize field validation (for address step)
        this.initializeFieldValidation();
        
        // Initialize company search for address step
        if (this.config.companySearchEnabled && this.currentStep === 'address' && this.isBusinessAccount) {
            this.initializeCompanySearch();
        }
        
        // Initialize phone validation on address step
        if (this.currentStep === 'address' && window.TwoPhoneValidation) {
            try { new TwoPhoneValidation(); } catch (e) {}
        }

        // Initialize order intent for payment step with business accounts
        if (this.config.orderIntentEnabled && this.currentStep === 'payment' && this.isBusinessAccount) {
            this.initializeOrderIntent();
            // If Two is already selected by default (only payment method), trigger intent once
            if (this.isTwoPaymentSelected() && !this._initialIntentTriggered) {
                this._initialIntentTriggered = true;
                this.triggerOrderIntentForSelection();
            }
        }
    }
    
    /**
     * Initialize company search module
     */
    initializeCompanySearch() {
        if (!this.companySearch && window.TwoCompanySearch) {
            this.companySearch = new TwoCompanySearch({
                checkoutHost: this.config.checkoutHost
            });
        }
    }
    
    /**
     * Initialize field validation module
     */
    initializeFieldValidation() {
        if (!this.fieldValidation && window.TwoFieldValidation) {
            this.fieldValidation = new TwoFieldValidation();
        }
    }

    /**
     * Initialize order intent module
     */
    initializeOrderIntent() {
        if (!this.orderIntent && window.TwoOrderIntent) {
            const useAccountType = !!(window.twopayment && String(window.twopayment.use_account_type) === '1');
            this.orderIntent = new TwoOrderIntent({
                enabled: true,
                orderIntentUrl: this.config.orderIntentUrl,
                ajaxToken: this.config.ajaxToken,
                enablePaymentPreventionOnDecline: useAccountType // do not globally block when account type is disabled
            });
        }
    }
    
    /**
     * Get company data (for compatibility)
     */
    getCompanyData() {
        if (this.companySearch && this.companySearch.getCompanyData) {
            return this.companySearch.getCompanyData();
        }
        return { company: '', companyid: '' };
    }
    
    /**
     * Check if Two payment is available
     */
    isTwoPaymentAvailable() {
        return !!this.twoPaymentOption;
    }
    
    /**
     * Manual order intent trigger (for compatibility)
     */
    triggerOrderIntent() {
        if (this.orderIntent) {
            return this.orderIntent.checkOrderIntent();
        }
        return Promise.resolve({ success: false, error: 'Order intent not available' });
    }
    
    /**
     * Get debug info (for compatibility)
     */
    getDebugInfo() {
        return {
            currentStep: this.currentStep,
            isBusinessAccount: this.isBusinessAccount,
            twoPaymentAvailable: this.isTwoPaymentAvailable(),
            twoPaymentSelected: this.isTwoPaymentSelected(),
            companySearchReady: !!(this.companySearch && this.companySearch.isReady),
            orderIntentReady: !!this.orderIntent,
            isInitialized: this.isInitialized
        };
    }
}

// Export for use in other modules
window.TwoCheckoutManager = TwoCheckoutManager;
