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
        
        console.log('TwoCheckoutManager: Constructor config:', this.config);
        
        this.companySearch = null;
        this.orderIntent = null;
        this.fieldValidation = null;
        this.currentStep = 'unknown';
        this.isBusinessAccount = false;
        this.isInitialized = false;
        this.twoPaymentOption = null;
        
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
        this.isBusinessAccount = !!this.twoPaymentOption;

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
        
        if (isTwoSelected && this.orderIntent && this.config.orderIntentEnabled) {
            // Two payment selected - trigger order intent validation
            this.triggerOrderIntentForSelection();
        } else if (this.orderIntent) {
            // Different payment selected - clear any Two-specific UI
            this.clearOrderIntentUI();
        }
    }
    
    /**
     * Check if Two payment is selected (theme-independent)
     */
    isTwoPaymentSelected(radioButton) {
        if (!radioButton) {
            // Check current selection
            const checkedRadio = document.querySelector('input[type="radio"][name*="payment"]:checked');
            radioButton = checkedRadio;
        }
        
        if (!radioButton) return false;
        
        // Various ways Two payment can be identified
        return radioButton.value === 'twopayment' ||
               radioButton.id === 'payment-option-twopayment' ||
               radioButton.closest('[data-module-name="twopayment"]') !== null ||
               (radioButton.name && radioButton.name.includes('twopayment'));
    }
    
    /**
     * Handle Two payment selection specifically
     */
    handleTwoPaymentSelection() {
        if (this.orderIntent && this.config.orderIntentEnabled) {
            this.triggerOrderIntentForSelection();
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
        
        // Show loading state
        this.showOrderIntentLoading();
        
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
            this.showOrderIntentError(result.error || 'Order intent check failed');
            return;
        }
        
        if (result.approved) {
            this.showOrderIntentApproval(result.message);
        } else {
            // For declined results, also check if the decline reason should be treated as an error
            const declineMessage = result.message || 'Payment declined';
            if (this.isDeclineReasonAnError(declineMessage)) {
                this.showOrderIntentError(declineMessage);
            } else {
                this.showOrderIntentDecline(declineMessage);
                this.disableTwoPayment();
            }
        }
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
        const messageContainer = this.getOrCreateMessageContainer();
        messageContainer.innerHTML = `
            <div class="alert alert-info" role="alert">
                <div class="two-loading-container">
                    <div class="two-loading-spinner"></div>
                    <span class="two-loading-text">Verifying payment eligibility...</span>
                </div>
            </div>
        `;
        messageContainer.style.display = 'block';
        
        // Add loading class to payment option for visual feedback
        if (this.twoPaymentOption) {
            this.twoPaymentOption.classList.add('two-payment-loading');
        }
    }

    /**
     * Show order intent approval message and payment terms (theme-independent)
     */
    showOrderIntentApproval(message) {
        console.log('TwoCheckoutManager: Showing order intent approval');
        const messageContainer = this.getOrCreateMessageContainer();
        
        // Update the payment info section with success message
        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.innerHTML = message || 'Payment approved! Choose your payment terms below.';
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">Payment Approved</p>
                <p class="two-payment-message">${message || 'Payment approved! Choose your payment terms below.'}</p>
            `;
        }
        
        // Add approved styling to container
        messageContainer.classList.remove('declined', 'loading');
        messageContainer.classList.add('approved', 'show');
        messageContainer.style.display = 'block';
        
        // Remove loading state and add approved state to payment option
        this.clearLoadingState();
        if (this.twoPaymentOption) {
            this.twoPaymentOption.classList.add('two-payment-approved');
        }
        
        // Show payment terms selector
        console.log('TwoCheckoutManager: About to show payment terms');
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
            messageElement.innerHTML = message || 'Two payment is not available for this order.';
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">Payment Not Available</p>
                <p class="two-payment-message">${message || 'Two payment is not available for this order.'}</p>
            `;
        }
        
        // Add declined styling to container
        messageContainer.classList.remove('approved', 'loading');
        messageContainer.classList.add('declined', 'show');
        messageContainer.style.display = 'block';
        
        // Remove loading state and add declined state to payment option
        this.clearLoadingState();
        if (this.twoPaymentOption) {
            this.twoPaymentOption.classList.add('two-payment-declined');
        }
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
                <p class="two-subtitle">Action Required</p>
                <p class="two-payment-message">${userFriendlyError}</p>
            `;
        }
        
        // Add error styling to container
        messageContainer.classList.remove('approved', 'loading', 'declined');
        messageContainer.classList.add('show');
        messageContainer.style.display = 'block';
        
        // Remove loading state and add error state to payment option
        this.clearLoadingState();
        if (this.twoPaymentOption) {
            this.twoPaymentOption.classList.add('two-payment-error');
        }
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
        if (this.twoPaymentOption) {
            this.twoPaymentOption.classList.remove(
                'two-payment-loading', 
                'two-payment-approved', 
                'two-payment-declined', 
                'two-payment-error'
            );
        }
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
            console.log('TwoCheckoutManager: Payment terms container shown and initialized');
        } else {
            console.error('TwoCheckoutManager: Payment terms container (#two-payment-terms) not found in DOM');
            // Try to find it in the entire document
            const allTermsElements = document.querySelectorAll('[id*="payment-terms"], [class*="payment-terms"]');
            console.log('TwoCheckoutManager: Found payment terms elements:', allTermsElements);
        }
    }

    /**
     * Initialize payment terms selector with available terms
     */
    initializePaymentTerms() {
        const termsSlider = document.querySelector('#two-terms-slider');
        const selectedDays = document.querySelector('#two-selected-days');
        
        console.log('TwoCheckoutManager: Initializing payment terms', {
            termsSlider: !!termsSlider,
            selectedDays: !!selectedDays,
            hasChildNodes: termsSlider ? termsSlider.hasChildNodes() : false
        });
        
        if (!termsSlider) {
            console.error('TwoCheckoutManager: Terms slider (#two-terms-slider) not found');
            return;
        }
        
        if (termsSlider.hasChildNodes()) {
            console.log('TwoCheckoutManager: Payment terms already have content, clearing and reinitializing');
            // Clear existing content to reinitialize
            termsSlider.innerHTML = '';
        }
        
        // Get payment terms from admin configuration (passed via template)
        const availableTerms = this.config.available_payment_terms;
        const defaultTerm = this.config.default_payment_term;
        
        console.log('TwoCheckoutManager: Payment terms config', {
            availableTerms,
            defaultTerm,
            configAvailable: !!this.config.available_payment_terms,
            configDefaultTerm: !!this.config.default_payment_term
        });
        
        // If no terms configured, don't show payment terms
        if (!availableTerms || !Array.isArray(availableTerms) || availableTerms.length === 0) {
            console.error('TwoCheckoutManager: No payment terms configured in admin');
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
        if (this.twoPaymentRadio) {
            this.twoPaymentRadio.disabled = true;
        }
        if (this.twoPaymentOption) {
            this.twoPaymentOption.classList.add('payment-option-disabled');
            this.twoPaymentOption.style.opacity = '0.5';
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
            if (this.companySearch && this.companySearch.destroy) {
                this.companySearch.destroy();
                this.companySearch = null;
            }
            if (this.isBusinessAccount) {
                this.initializeCompanySearch();
            }
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
            // Ensure an order intent check is triggered on confirm
            this.triggerOrderIntentForSelection();
            // Optional: block only if we already know it's declined
            if (this.orderIntent.lastResult && !this.orderIntent.lastResult.approved) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                this.showOrderIntentError('Payment approval required before proceeding');
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
        
        // Initialize order intent for payment step with business accounts
        if (this.config.orderIntentEnabled && this.currentStep === 'payment' && this.isBusinessAccount) {
            this.initializeOrderIntent();
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
            this.orderIntent = new TwoOrderIntent({
                enabled: true,
                orderIntentUrl: this.config.orderIntentUrl,
                ajaxToken: this.config.ajaxToken
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
