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

    t(key, fallback) {
        if (window.twopayment && window.twopayment.i18n && window.twopayment.i18n[key]) {
            return window.twopayment.i18n[key];
        }
        return fallback;
    }

    escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
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
     * ENHANCED: Account type detection with extensive fallback chain for compatibility
     */
    detectAccountType() {
        // ENHANCED: Try multiple methods to find Two payment option for better compatibility
        this.twoPaymentOption = this.detectTwoPaymentOption();

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
     * ENHANCED: Detect Two payment option using multiple strategies for maximum compatibility
     */
    detectTwoPaymentOption() {
        // Strategy 1: Direct data-module-name attribute (most reliable)
        let paymentOption = document.querySelector('[data-module-name="twopayment"]');
        if (paymentOption) {
            return paymentOption;
        }
        
        // Strategy 2: Look for payment option containing twopayment input
        paymentOption = document.querySelector('.payment-option input[data-module-name="twopayment"]');
        if (paymentOption) {
            paymentOption = paymentOption.closest('.payment-option');
            if (paymentOption) {
                return paymentOption;
            }
        }
        
        // Strategy 3: Search by form action containing 'twopayment'
        const paymentForms = document.querySelectorAll('.payment-option form, form[action*="twopayment"]');
        for (const form of paymentForms) {
            if (form.action && form.action.includes('twopayment')) {
                paymentOption = form.closest('.payment-option') || form.closest('.payment-option-container') || form.parentElement;
                if (paymentOption) {
                    return paymentOption;
                }
            }
        }
        
        // Strategy 4: Search all payment options for Two logo or text
        const allPaymentOptions = document.querySelectorAll('.payment-option, [class*="payment-option"], [id*="payment-option"]');
        for (const option of allPaymentOptions) {
            const optionText = option.textContent || '';
            const hasLogo = option.querySelector('img[src*="two"], img[alt*="two" i], img[alt*="Two"]');
            const hasTwoText = optionText.toLowerCase().includes('two') && 
                              (optionText.toLowerCase().includes('pay') || optionText.toLowerCase().includes('invoice'));
            
            if (hasLogo || hasTwoText) {
                // Verify this is actually Two payment by checking for our module containers
                const hasTwoContainer = option.querySelector('.two-payment-container, .two-payment-info, #two-payment-terms');
                if (hasTwoContainer) {
                    return option;
                }
            }
        }
        
        // Strategy 5: Look for our template container and traverse up to payment option
        const twoContainer = document.querySelector('.two-payment-container');
        if (twoContainer) {
            // Traverse up to find payment option container
            let parent = twoContainer.parentElement;
            let maxDepth = 10;
            while (parent && maxDepth > 0) {
                if (parent.classList.contains('payment-option') || 
                    parent.id && parent.id.includes('payment-option')) {
                    return parent;
                }
                parent = parent.parentElement;
                maxDepth--;
            }
        }
        
        return null;
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
     * ENHANCED: Only trigger order intent when Two payment is selected (more comprehensive detection)
     */
    setupPaymentOptionSelectionListener() {
        // Prevent duplicate listeners
        if (this._paymentListenersAttached) return;
        this._paymentListenersAttached = true;
        
        // Method 1: Listen for payment radio button changes with comprehensive selectors
        document.addEventListener('change', (event) => {
            const paymentRadioSelectors = [
                'input[type="radio"][name*="payment"]',
                'input[name="payment-option"]', 
                'input[name="payment_method"]',
                '.payment-options input[type="radio"]',
                '.payment-option input[type="radio"]'
            ];
            
            if (paymentRadioSelectors.some(selector => event.target.matches(selector))) {
                this.handlePaymentOptionChange(event.target);
            }
        });
        
        // Method 2: Listen for clicks on payment option containers with enhanced detection
        document.addEventListener('click', (event) => {
            // Check for Two payment option container clicks
            const twoPaymentSelectors = [
                '[data-module-name="twopayment"]',
                '.payment-option[data-module-name="twopayment"]',
                '.payment-option-content[data-module="twopayment"]'
            ];
            
            let paymentOption = null;
            for (const selector of twoPaymentSelectors) {
                paymentOption = event.target.closest(selector);
                if (paymentOption) break;
            }
            
            if (paymentOption) {
                // Small delay to allow radio button state to update
                setTimeout(() => this.handleTwoPaymentSelection(), 50);
            }
        });
        
        // Method 3: Listen for form submissions and payment confirmation attempts
        document.addEventListener('click', (event) => {
            const confirmationSelectors = [
                '#payment-confirmation button',
                '.payment-confirmation button', 
                'button[name="confirmDeliveryOption"]',
                'button[type="submit"][form*="payment"]',
                '.checkout button[type="submit"]',
                '.btn-primary[type="submit"]',
                'button.btn[name*="confirm"]'
            ];
            
            if (confirmationSelectors.some(selector => event.target.matches(selector))) {
                this.handlePaymentConfirmation(event);
            }
        });
        
        // Method 4: Enhanced form submission listener (catch-all for different themes)
        document.addEventListener('submit', (event) => {
            // Check if this is a payment/checkout form
            const form = event.target;
            if (form && (form.action.includes('payment') || 
                        form.action.includes('checkout') ||
                        form.action.includes('order') ||
                        form.querySelector('input[name*="payment"]'))) {
                this.handlePaymentConfirmation(event);
            }
        });
        
        // Method 5: Periodic check for Two payment selection (fallback for complex themes)
        this._selectionCheckInterval = setInterval(() => {
            if (this.isTwoPaymentSelected() && this.config.orderIntentEnabled) {
                // Only trigger if we haven't processed this selection recently AND we don't have a result yet
                const hasResult = this.orderIntent && this.orderIntent.lastResult;
                const recentlyChecked = this._lastSelectionCheck && (Date.now() - this._lastSelectionCheck < 5000);
                
                if (!hasResult && !recentlyChecked && !this.isLoadingUIShown) {
                    this._lastSelectionCheck = Date.now();
                    if (!this.orderIntent && window.TwoOrderIntent) {
                        this.initializeOrderIntent();
                    }
                    if (this.orderIntent) {
                        this.triggerOrderIntentForSelection();
                    }
                }
            }
        }, 3000);
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
            // Different payment selected - clear any Two-specific UI and reset state
            this.clearOrderIntentUI();
            // Reset the result so if user switches back, we check again
            if (this.orderIntent && this.orderIntent.lastResult) {
                this.orderIntent.lastResult = null;
            }
            // Clear server-side result when switching away from Two
            this.clearOrderIntentResultFromServer();
        }
    }
    
    /**
     * ENHANCED: Check if Two payment is selected (theme-independent with better detection)
     */
    isTwoPaymentSelected(radioButton) {
        // Multiple strategies for different PrestaShop themes and versions
        
        // Strategy 1: Use provided radio button
        if (radioButton) {
            const twoOption = this.twoPaymentOption || document.querySelector('[data-module-name="twopayment"]');
            if (twoOption && twoOption.contains(radioButton)) {
                return true;
            }
        }
        
        // Strategy 2: Find currently checked radio with various selectors
        const radioSelectors = [
            'input[type="radio"][name="payment-option"]:checked',
            'input[type="radio"][name*="payment"]:checked',
            'input[name="payment_method"]:checked',
            '.payment-options input[type="radio"]:checked',
            '.payment-option input[type="radio"]:checked'
        ];
        
        let checkedRadio = null;
        for (const selector of radioSelectors) {
            checkedRadio = document.querySelector(selector);
            if (checkedRadio) break;
        }
        
        if (checkedRadio) {
            // Check if this radio is inside Two payment option
            const twoOption = this.twoPaymentOption || document.querySelector('[data-module-name="twopayment"]');
            if (twoOption && twoOption.contains(checkedRadio)) {
                return true;
            }
            
            // Value-based detection for different theme structures
            if (checkedRadio.value === 'twopayment' || 
                checkedRadio.id === 'payment-option-twopayment' ||
                checkedRadio.value.includes('twopayment')) {
                return true;
            }
        }
        
        // Strategy 3: Check for Two payment container with active/selected class
        const twoContainers = document.querySelectorAll('[data-module-name="twopayment"]');
        for (const container of twoContainers) {
            if (container.classList.contains('selected') || 
                container.classList.contains('active') ||
                container.querySelector('input[type="radio"]:checked')) {
                return true;
            }
        }
        
        // Strategy 4: Check for Two payment in URL or form action (for some themes)
        const forms = document.querySelectorAll('form');
        for (const form of forms) {
            if (form.action && form.action.includes('twopayment')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle Two payment selection specifically
     */
    handleTwoPaymentSelection() {
        const isTwoSelected = this.isTwoPaymentSelected();
        
        if (isTwoSelected && this.config.orderIntentEnabled) {
            if (!this.orderIntent && window.TwoOrderIntent) {
                this.initializeOrderIntent();
            }
            if (this.orderIntent) {
                this.triggerOrderIntentForSelection();
            }
        } else if (this.orderIntent) {
            // Clear order intent UI when switching away from Two
            this.clearOrderIntentUI();
            if (this.orderIntent && this.orderIntent.lastResult) {
                this.orderIntent.lastResult = null;
            }
            // Clear server-side result when switching away from Two
            this.clearOrderIntentResultFromServer();
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
        
        // CRITICAL: If we already have a result, don't check again
        // This prevents infinite loops from periodic checks
        if (this.orderIntent && this.orderIntent.lastResult && this.orderIntent.lastResult.success !== undefined) {
            // Just show the existing result
            this.handleOrderIntentResult(this.orderIntent.lastResult);
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
            this.showOrderIntentError(error.message || this.t('order_intent_check_failed', 'Order intent check failed'));
        });
    }
    
    /**
     * Handle order intent result and update UI accordingly
     */
    handleOrderIntentResult(result) {
        if (!result.success) {
            // ENHANCED: Handle specific company status codes from backend
            const status = result.status || '';
            const err = (result && result.error) ? String(result.error) : '';
            const errLower = err.toLowerCase();
            const useAccountType = !!(window.twopayment && String(window.twopayment.use_account_type) === '1');
            
            // Handle specific status codes for clear user guidance
            // 'no_company' = no company name entered at all
            // 'incomplete_company' = company name exists but backend couldn't auto-resolve org number
            if (status === 'no_company') {
                this.showCompanyRequiredMessage(err, status);
                return;
            }
            
            if (status === 'incomplete_company') {
                // Backend tried to auto-resolve but couldn't find a confident match
                // Show message asking user to search and select their company
                this.showCompanyRequiredMessage(err, status);
                return;
            }
            
            // Legacy: If order intent was skipped (frontend-side skip), show appropriate prompt
            if (errLower.includes('skipped_no_company') && !useAccountType) {
                this.showCompanyRequiredMessage(err, 'no_company');
                return;
            }
            
            if (errLower.includes('skipped') && !useAccountType) {
                // Generic skip - show company selection prompt
                const messageContainer = this.getOrCreateMessageContainer();
                const requiredMsg = this.t(
                    'select_company_to_use_two',
                    'To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'
                );
                const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
                if (messageElement !== messageContainer) {
                    messageElement.textContent = requiredMsg;
                } else {
                    messageContainer.innerHTML = `
                        <p class="two-subtitle">${this.escapeHtml(this.t('action_required_title', 'Action Required'))}</p>
                        <p class="two-payment-message">${this.escapeHtml(requiredMsg)}</p>
                    `;
                }
                messageContainer.classList.remove('approved', 'loading');
                messageContainer.classList.add('show');
                messageContainer.style.display = 'block';
                this.hideLoadingOverlay();
                return;
            }
            this.showOrderIntentError(result.error || this.t('order_intent_check_failed', 'Order intent check failed'));
            return;
        }

        // Save order intent result to server for server-side validation
        // This prevents bypassing client-side blocking by disabling JavaScript
        this.saveOrderIntentResultToServer(result.approved);

        // Build company-aware message for display (translated)
        const companyName = this.getSelectedCompanyName();
        if (result.approved) {
            let approvedMsg = result.message || this.t('payment_approved_message', 'Payment approved! Choose your payment terms below.');
            if (companyName && window.twopayment && window.twopayment.i18n && window.twopayment.i18n.invoice_likely_accepted_for) {
                approvedMsg = window.twopayment.i18n.invoice_likely_accepted_for.replace('%s', companyName);
            }
            this.showOrderIntentApproval(approvedMsg);
        } else {
            // For declined results, also check if the decline reason should be treated as an error
            const baseDecline = result.message || this.t('payment_not_available_message', 'Two payment is not available for this order.');
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
     * Show company required message with clear guidance (theme-independent)
     * @param {string} message - The error message from backend
     * @param {string} status - The status code: 'no_company' or 'incomplete_company'
     */
    showCompanyRequiredMessage(message, status) {
        const messageContainer = this.getOrCreateMessageContainer();
        const actionTitle = this.t('action_required_title', 'Action Required');
        
        // Determine help text based on status
        let helpText = '';
        if (status === 'no_company') {
            helpText = this.t(
                'company_name_required',
                'To pay with Two, go back to your billing address and enter your company name in the Company field.'
            );
        } else if (status === 'incomplete_company') {
            // IMPROVED: When auto-resolution failed, give clearer guidance
            // The backend tried to find the company but couldn't get a confident match
            helpText = this.t(
                'select_company_to_use_two',
                'To pay with Two, go back to your billing address and search for your company name. Select your company from the search results to verify your business.'
            );
        }
        
        // Build the message UI
        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.textContent = message || helpText;
        } else {
            // For incomplete_company, show a more informative message
            const displayTitle = status === 'incomplete_company' 
                ? this.t('company_verification_needed', 'Company Verification Needed')
                : actionTitle;
            const displayMessage = message || helpText;
            
            messageContainer.innerHTML = `
                <p class="two-subtitle">${this.escapeHtml(displayTitle)}</p>
                <p class="two-payment-message">${this.escapeHtml(displayMessage)}</p>
                ${helpText && message !== helpText ? `<p class="two-help-text">${this.escapeHtml(helpText)}</p>` : ''}
            `;
        }
        
        // Apply styling
        messageContainer.classList.remove('approved', 'loading', 'declined');
        messageContainer.classList.add('show', 'action-required');
        messageContainer.style.display = 'block';
        
        // Hide loading overlay
        this.hideLoadingOverlay();
        
        // Don't show payment terms - company verification required first
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
                    <span class="two-loading-text">${this.t('checking_eligibility', 'Checking Two payment eligibility...')}</span>
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
        const approvedMessage = message || this.t('payment_approved_message', 'Payment approved! Choose your payment terms below.');
        if (messageElement !== messageContainer) {
            messageElement.textContent = approvedMessage;
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">${this.escapeHtml(this.t('payment_approved_title', 'Payment Approved'))}</p>
                <p class="two-payment-message">${this.escapeHtml(approvedMessage)}</p>
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
        const declineMessage = message || this.t('payment_not_available_message', 'Two payment is not available for this order.');
        if (messageElement !== messageContainer) {
            messageElement.textContent = declineMessage;
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">${this.escapeHtml(this.t('payment_not_available_title', 'Payment Not Available'))}</p>
                <p class="two-payment-message">${this.escapeHtml(declineMessage)}</p>
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
     * SMART: If company data is missing, show company-specific message instead of generic error
     */
    showOrderIntentError(error) {
        // SMART CHECK: Before showing generic error, check if company data is actually missing
        // This handles the common case where error is shown due to missing company selection
        const companyMissing = this.isCompanyDataMissing();
        
        let userFriendlyError;
        if (companyMissing) {
            // Company data is incomplete - show specific guidance
            userFriendlyError = this.t(
                'select_company_to_use_two',
                'To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'
            );
        } else {
            // Company data looks complete - show generic error
            userFriendlyError = this.t(
                'generic_error',
                'There was an issue processing your Two payment request. Please try again or choose another payment method.'
            );
        }
        
        const messageContainer = this.getOrCreateMessageContainer();
        
        // Update the payment info section with error message
        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.textContent = userFriendlyError;
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">${this.escapeHtml(this.t('action_required_title', 'Action Required'))}</p>
                <p class="two-payment-message">${this.escapeHtml(userFriendlyError)}</p>
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
     * Check if company data is missing (org number not selected)
     * @returns {boolean} True if company org number is missing
     */
    isCompanyDataMissing() {
        let orgNumber = '';
        
        // Check companyid form field
        const companyIdField = document.querySelector("input[name='companyid']");
        if (companyIdField && companyIdField.value) {
            orgNumber = companyIdField.value.trim();
        }
        
        // Also check cookie as fallback
        if (!orgNumber) {
            try {
                const cookieMatch = document.cookie.match(/two_company_id=([^;]+)/);
                if (cookieMatch) {
                    orgNumber = decodeURIComponent(cookieMatch[1]).trim();
                }
            } catch (e) { /* ignore */ }
        }
        
        return !orgNumber;
    }

    /**
     * Convert technical error messages to user-friendly ones
     */
    getUserFriendlyErrorMessage(error) {
        // Handle specific error cases
        if (typeof error === 'string') {
            const errorLower = error.toLowerCase();
            
            // Case: Invalid phone number (from Two API validation)
            if (errorLower.includes('invalid phone number') || 
                (errorLower.includes('phone_number') && errorLower.includes('value_error'))) {
                return this.t(
                    'invalid_phone_number',
                    'The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country.'
                );
            }
            
            // Case: "Company name is required for business accounts"
            if (errorLower.includes('company name is required')) {
                return this.t(
                    'company_name_required',
                    'To pay with Two, go back to your billing address and enter your company name in the Company field.'
                );
            }
            
            // Case: "Organization number is required"
            if (errorLower.includes('organization number') && errorLower.includes('required')) {
                return this.t(
                    'select_company_to_use_two',
                    'Go back to your billing address and search for your company name. Select your company from the results to verify your business.'
                );
            }
            
            // Case: "Invalid company information"
            if (errorLower.includes('invalid company')) {
                return this.t(
                    'invalid_company',
                    'The company information provided is not valid. Go back to your billing address and select a valid company from the search results.'
                );
            }
            
            // Case: "Company not found"
            if (errorLower.includes('company not found')) {
                return this.t(
                    'company_not_found',
                    'We could not find your company in our database. Please try searching with a different company name or contact support.'
                );
            }
            
            // Case: Invalid email
            if (errorLower.includes('invalid email') || 
                (errorLower.includes('email') && errorLower.includes('value_error'))) {
                return this.t('invalid_email', 'The email address provided is invalid. Please check your email and try again.');
            }
            
            // Case: Invalid address
            if (errorLower.includes('invalid address') || 
                (errorLower.includes('address') && errorLower.includes('value_error'))) {
                return this.t(
                    'invalid_address',
                    'The address provided is invalid. Please go back and verify your billing address details.'
                );
            }
            
            // Case: "Credit check failed" or similar
            if (errorLower.includes('credit') || errorLower.includes('not approved')) {
                return this.t(
                    'credit_unavailable',
                    'Two payment is not available for this order. Please choose another payment method.'
                );
            }
            
            // Case: API or network errors
            if (errorLower.includes('network') || errorLower.includes('timeout') || errorLower.includes('api')) {
                return this.t(
                    'network_issue',
                    'There was a temporary issue verifying your payment. Please try again or choose another payment method.'
                );
            }
            
            // Case: General validation error
            if (errorLower.includes('validation error') || errorLower.includes('value_error')) {
                return this.t(
                    'validation_error',
                    'Some of the information provided is invalid. Please check your billing address details and try again.'
                );
            }
        }
        
        // Default fallback for unknown errors
        return this.t(
            'generic_error',
            'There was an issue processing your Two payment request. Please try again or choose another payment method.'
        );
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
     * ENHANCED: Show payment terms selector with robust fallback for different themes
     */
    showPaymentTerms() {
        // Strategy 1: Direct ID lookup
        let termsContainer = document.querySelector('#two-payment-terms');
        
        // Strategy 2: Search by class
        if (!termsContainer) {
            termsContainer = document.querySelector('.two-payment-terms');
        }
        
        // Strategy 3: Search within Two payment container
        if (!termsContainer) {
            const twoContainer = document.querySelector('.two-payment-container');
            if (twoContainer) {
                termsContainer = twoContainer.querySelector('[id*="payment-terms"], [class*="payment-terms"]');
            }
        }
        
        // Strategy 4: Search within payment option
        if (!termsContainer && this.twoPaymentOption) {
            termsContainer = this.twoPaymentOption.querySelector('[id*="payment-terms"], [class*="payment-terms"]');
        }
        
        // Strategy 5: Search in additional information section
        if (!termsContainer) {
            const additionalInfo = document.querySelector('#payment-option-1-additional-information, .additional-information, .js-additional-information');
            if (additionalInfo) {
                termsContainer = additionalInfo.querySelector('[id*="payment-terms"], [class*="payment-terms"]');
            }
        }
        
        if (termsContainer) {
            // First make it visible, then add animation class
            termsContainer.style.display = 'block';
            termsContainer.style.visibility = 'visible';
            termsContainer.style.opacity = '1';
            
            // Force a reflow before adding the show class for animation
            termsContainer.offsetHeight;
            termsContainer.classList.add('show');
            
            // Initialize payment terms if not already done
            this.initializePaymentTerms();
            
        } else {
            console.warn('Two Payment: Payment terms container not found - template may not be rendered');
            
            // Last resort: Try to inject payment terms if template is missing
            this.injectPaymentTermsIfMissing();
        }
    }
    
    /**
     * FALLBACK: Inject payment terms dynamically if template is missing
     */
    injectPaymentTermsIfMissing() {
        // Find a suitable container to inject into
        let targetContainer = this.getOrCreateMessageContainer();
        
        if (!targetContainer) {
            console.error('Two Payment: Cannot inject payment terms - no target container found');
            return;
        }
        
        // Create payment terms HTML
        const termsHtml = `
            <div class="two-payment-terms" id="two-payment-terms" style="display: block;">
                <div class="two-terms-header">
                    <h4 class="two-terms-title">${this.t('choose_payment_terms', 'Choose the Buy Now, Pay Later option that works best for you')}</h4>
                    <p class="two-terms-description">${this.t('payment_period_starts', 'Your payment period starts when your order is fulfilled')}</p>
                </div>
                <div class="two-terms-slider-container">
                    <div class="two-terms-slider" id="two-terms-slider">
                        <!-- Terms will be populated by JavaScript -->
                    </div>
                    <div class="two-terms-selected">
                        <span class="two-terms-selected-days" id="two-selected-days"></span>
                    </div>
                </div>
            </div>
        `;
        
        // Inject after message container
        targetContainer.insertAdjacentHTML('afterend', termsHtml);
        
        // Initialize the newly created terms
        this.initializePaymentTerms();
    }

    /**
     * ENHANCED: Initialize payment terms selector with robust error handling
     */
    initializePaymentTerms() {
        // Try multiple selectors for terms slider
        let termsSlider = document.querySelector('#two-terms-slider');
        if (!termsSlider) {
            termsSlider = document.querySelector('.two-terms-slider');
        }
        
        let selectedDays = document.querySelector('#two-selected-days');
        if (!selectedDays) {
            selectedDays = document.querySelector('.two-terms-selected-days');
        }
        
        if (!termsSlider) {
            console.error('Two Payment: Terms slider element not found');
            return;
        }
        
        // Check if already initialized with our terms
        if (termsSlider.hasChildNodes() && termsSlider.querySelector('.two-term-option')) {
            return;
        }
        
        if (termsSlider.hasChildNodes()) {
            // Clear existing content to reinitialize
            termsSlider.innerHTML = '';
        }
        
        // Get payment terms from admin configuration (passed via template)
        const availableTerms = this.config.available_payment_terms;
        const defaultTerm = this.config.default_payment_term;
        const termType = this.config.payment_term_type || 'STANDARD';
        
        // If no terms configured, don't show payment terms
        if (!availableTerms || !Array.isArray(availableTerms) || availableTerms.length === 0) {
            return;
        }
        
        // Update description based on term type
        var termsDescription = document.querySelector('#two-terms-description');
        if (termsDescription) {
            if (termType === 'EOM') {
                var eomText = termsDescription.getAttribute('data-eom-text');
                if (eomText) {
                    termsDescription.textContent = eomText;
                }
            } else {
                var standardText = termsDescription.getAttribute('data-standard-text');
                if (standardText) {
                    termsDescription.textContent = standardText;
                }
            }
        }
        
        // Create term options
        availableTerms.forEach(function(days, index) {
            const termOption = document.createElement('div');
            termOption.className = 'two-term-option';
            
            // Format display based on term type (EOM+X for End-of-Month, X for Standard)
            if (termType === 'EOM') {
                termOption.textContent = 'EOM+' + days;
                termOption.title = this.t('end_of_month_plus_days', 'End of Month + %s days').replace('%s', days);
            } else {
                termOption.textContent = days;
                termOption.title = days + ' ' + this.t('days', 'days');
            }
            
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
                
                // Update selected term display
                if (selectedDays) {
                    const payInText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.pay_in 
                        ? window.twopayment.i18n.pay_in 
                        : 'Pay in';
                    const daysText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.days 
                        ? window.twopayment.i18n.days 
                        : 'days';
                    const fromEndOfMonthText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.from_end_of_month 
                        ? window.twopayment.i18n.from_end_of_month 
                        : 'from end of month';

                    if (termType === 'EOM') {
                        // For EOM: Show "Pay in X days from end of month" format
                        selectedDays.textContent = payInText + ' ' + days + ' ' + daysText + ' ' + fromEndOfMonthText;
                    } else {
                        // For Standard: Show "Pay in 30 days" format
                        selectedDays.textContent = payInText + ' ' + days + ' ' + daysText;
                    }
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
                } catch (e) {
                    console.error('Two Payment: Error saving term:', e);
                }
            });
            
            termsSlider.appendChild(termOption);
        });
        
        // Set initial selected term display
        const activeTerm = defaultTerm || (availableTerms.length === 1 ? availableTerms[0] : availableTerms[0]);
        
        if (selectedDays && activeTerm) {
            const payInText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.pay_in 
                ? window.twopayment.i18n.pay_in 
                : 'Pay in';
            const daysText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.days 
                ? window.twopayment.i18n.days 
                : 'days';
            const fromEndOfMonthText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.from_end_of_month 
                ? window.twopayment.i18n.from_end_of_month 
                : 'from end of month';

            if (termType === 'EOM') {
                // For EOM: Show "Pay in X days from end of month" format
                selectedDays.textContent = payInText + ' ' + activeTerm + ' ' + daysText + ' ' + fromEndOfMonthText;
            } else {
                // For Standard: Show "Pay in 30 days" format
                selectedDays.textContent = payInText + ' ' + activeTerm + ' ' + daysText;
            }
        }
    }
    
    /**
     * Update payment terms description based on term type
     * Separated for reusability and early initialization
     */
    /**
     * Save order intent result to server for server-side validation
     * Prevents bypassing client-side blocking
     */
    saveOrderIntentResultToServer(approved) {
        if (!this.config.orderIntentUrl || !window.twopayment || !window.twopayment.ajax_token) {
            return;
        }

        $.ajax({
            url: this.config.orderIntentUrl,
            type: 'POST',
            data: {
                ajax: 1,
                action: 'saveOrderIntentResult',
                approved: approved ? 1 : 0,
                token: window.twopayment.ajax_token
            },
            success: () => {
                // Result saved for server-side validation
            },
            error: (xhr, status, error) => {
                // Log but don't block - client-side blocking still works
                console.warn('TwoPayment: Failed to save order intent result to server:', error);
            }
        });
    }

    /**
     * Clear order intent result from server
     * Called when user switches away from Two payment method
     */
    clearOrderIntentResultFromServer() {
        if (!this.config.orderIntentUrl || !window.twopayment || !window.twopayment.ajax_token) {
            return;
        }

        $.ajax({
            url: this.config.orderIntentUrl,
            type: 'POST',
            data: {
                ajax: 1,
                action: 'clearOrderIntentResult',
                token: window.twopayment.ajax_token
            },
            success: () => {
                // Result cleared
            },
            error: () => {
                // Non-critical - just log
                console.warn('TwoPayment: Failed to clear order intent result from server');
            }
        });
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
        if (this._mutationObserver) {
            this._mutationObserver.disconnect();
            this._mutationObserver = null;
        }

        this._mutationObserver = new MutationObserver((mutations) => {
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
        
        this._mutationObserver.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    /**
     * ENHANCED: Handle dynamic content changes with retry mechanism
     */
    handleDynamicContentChange() {
        // Re-detect everything
        this.detectCheckoutStep();
        const previousPaymentOption = this.twoPaymentOption;
        this.detectAccountType();
        
        // If we previously couldn't find the payment option but now we can, reinitialize
        if (!previousPaymentOption && this.twoPaymentOption) {
            this.initializeModules();
        }
        
        // Re-setup payment listeners (idempotent, won't duplicate)
        this._paymentListenersAttached = false;
        this.setupPaymentOptionSelectionListener();
        
        // Initialize modules if needed
        if (this.isBusinessAccount && this.config.orderIntentEnabled && !this.orderIntent) {
            this.initializeOrderIntent();
        }
        
        // If on payment step and Two is selected, trigger order intent
        if (this.currentStep === 'payment' && this.isTwoPaymentSelected() && this.config.orderIntentEnabled) {
            setTimeout(() => {
                if (this.orderIntent && !this.isLoadingUIShown) {
                    this.triggerOrderIntentForSelection();
                }
            }, 500);
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

        // Phone validation removed - Two API handles validation
    }
    
    /**
     * Handle PrestaShop delivery form updates  
     */
    handleDeliveryFormUpdate() {
        // Update step detection as delivery affects checkout flow
        this.detectCheckoutStep();
    }
    
    /**
     * ENHANCED: Handle PrestaShop payment form updates with retry mechanism
     */
    handlePaymentFormUpdate() {
        this.detectAccountType();
        this.handleDynamicContentChange();
        
        // Reset order intent result when form updates so it can check again
        if (this.orderIntent && this.orderIntent.lastResult) {
            this.orderIntent.lastResult = null;
        }
        
        // If Two is available and selected after payment form refresh, ensure order intent runs
        if (this.config.orderIntentEnabled) {
            if (!this.orderIntent && window.TwoOrderIntent) {
                this.initializeOrderIntent();
            }
            
            // Only trigger once per form update - no retry loop
            if (this.isTwoPaymentSelected() && this.orderIntent) {
                // Small delay to let DOM settle
                setTimeout(() => {
                    this.triggerOrderIntentForSelection();
                }, 300);
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
                const msg = this.t('approval_required', 'Payment approval required before proceeding');
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
        
        // Phone validation removed - Two API handles validation

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
    
    /**
     * Cleanup method to prevent memory leaks
     */
    cleanup() {
        // Clear intervals
        if (this._selectionCheckInterval) {
            clearInterval(this._selectionCheckInterval);
            this._selectionCheckInterval = null;
        }
        
        if (this.reinitializeTimeout) {
            clearTimeout(this.reinitializeTimeout);
            this.reinitializeTimeout = null;
        }

        if (this._mutationObserver) {
            this._mutationObserver.disconnect();
            this._mutationObserver = null;
        }
        
        if (this._intentRetryTimeout) {
            clearTimeout(this._intentRetryTimeout);
            this._intentRetryTimeout = null;
        }
        
        // Reset state
        this._paymentListenersAttached = false;
        this._lastSelectionCheck = 0;
        this.isInitialized = false;
        
        // Cleanup child modules
        if (this.companySearch && typeof this.companySearch.destroy === 'function') {
            this.companySearch.destroy();
        }
        
        if (this.orderIntent && typeof this.orderIntent.reset === 'function') {
            this.orderIntent.reset();
        }
        
        if (this.fieldValidation && typeof this.fieldValidation.cleanup === 'function') {
            this.fieldValidation.cleanup();
        }
    }
}

// Export for use in other modules
window.TwoCheckoutManager = TwoCheckoutManager;
