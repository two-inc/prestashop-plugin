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
        this.currentStep = 'unknown';
        this.isBusinessAccount = false;
        this.isInitialized = false;
        this.twoPaymentOption = null;
        this.isLoadingUIShown = false;
        this._intentCooldownMs = 800;
        this._lastIntentRunAt = 0;
        this._initialIntentTriggered = false;
        // Monotonic sequence for surcharge cart-line syncs: only the LATEST
        // selection's response may drive the UI (last-wins against re-ordered
        // AJAX responses when the buyer clicks between options quickly), and
        // the same value is sent to the server so a slower OLDER request can
        // never overwrite a newer one there either. Seeded with Date.now()
        // (not 0) so the sequence stays monotonic across page reloads - the
        // server persists the last-applied value per cart.
        this._surchargeSyncSeq = Date.now();
        this._surchargeRestoreKey = 'two_restore_payment_selection';

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

        // If the native cart refresh (full payment-step reload) was triggered
        // by a surcharge line sync, restore the payment option the buyer had
        // clicked - the reload wipes radio state.
        this.restorePaymentSelectionAfterCartRefresh();

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
     * There is no account-type selector (TWO-24755 rework: B2B checkout
     * always allows company search and the Two flow; order intent gates
     * later by actual company presence, not a selector value).
     */
    detectAccountType() {
        this.twoPaymentOption = this.detectTwoPaymentOption();
        this.isBusinessAccount = true;
        
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

            // Cart-content changes while Two is selected (quantity spinners /
            // remove links on the checkout order-summary widget): core emits
            // 'updatedCart' AFTER it re-renders the summary (verified against
            // classic theme theme.js - distinct from the 'updateCart' event
            // this module itself emits to REQUEST a refresh). The fee is a
            // percentage of the cart basis, so re-quote and resync the line.
            // No loop risk: the server endpoint is idempotent - when nothing
            // changed it reports changed=false and no further refresh fires.
            //
            // ACCEPTED LIMITATION - admin mid-session tax-rate change: there
            // is no push channel from the back office into an open buyer
            // session, so a live payment step can display the old rate until
            // any of these triggers fires. The order-create self-heal always
            // reprices authoritatively and the server-side parity gate fails
            // closed, so the buyer can never be CHARGED off a stale rate.
            prestashop.on('updatedCart', () => {
                if (this.isTwoPaymentSelected()) {
                    this.syncSurchargeCartLine(true);
                }
            });
        }

        // Page-load resync: currency switching is a plain link in core (full
        // page reload - verified against ps_currencyselector), so if the
        // theme restores the Two selection after the reload the existing
        // line still carries the OLD currency's amount until resynced.
        // Idempotent no-op in the common case where nothing changed.
        if (this.isTwoPaymentSelected()) {
            this.syncSurchargeCartLine(true);
        }
        
        // CRITICAL: Listen for payment option selection (theme-independent)
        this.setupPaymentOptionSelectionListener();
        
        // Listen for DOM mutations for dynamic content
        this.setupMutationObserver();
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
            if (this.isPaymentConfirmationButton(event.target)) {
                this.handlePaymentConfirmation(event);
            }
        });
        
        // Method 4: Enhanced form submission listener (catch-all for different themes)
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (this.isPaymentConfirmationForm(form)) {
                this.handlePaymentConfirmation(event);
            }
        });
        
        // Method 5: Periodic check for Two payment selection (fallback for complex themes)
        this._selectionCheckInterval = setInterval(() => {
            this.detectCheckoutStep();
            if (this.currentStep !== 'payment') {
                return;
            }
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

        // Mirror the buyer surcharge as a real PrestaShop cart line (add on
        // Two selection, remove on any other selection). Server-side endpoint
        // is idempotent, so repeated change events are harmless.
        this.syncSurchargeCartLine(isTwoSelected);

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
     * Reconcile the cart's hidden surcharge line with the current payment
     * selection via the syncSurchargeLine AJAX action, then - only when the
     * server reports an actual cart change - trigger PrestaShop's NATIVE
     * cart-refresh plumbing so the order summary re-renders itself.
     *
     * FAILURE HANDLING (documented decision): the call is fail-soft in the
     * UI - one silent retry, then a console warning; checkout is never
     * blocked and the Place-order button is never touched from here. The
     * HARD guarantee that a buyer can never complete a Two order whose
     * PrestaShop total diverges from the Two invoice lives server-side: the
     * order-create path self-heals the cart line and fails closed on any
     * residual mismatch (Twopayment::buildTwoOrderPricingData parity gate).
     * A lost remove-sync for OTHER payment methods is likewise covered
     * server-side (actionFrontControllerInitAfter stale-guard strips the
     * line before any other payment module computes totals).
     *
     * @param {boolean} selected Two is the buyer's selected payment option
     * @returns {Promise<object>} the endpoint's {success, changed, present}
     */
    syncSurchargeCartLine(selected) {
        if (!window.twopayment || !window.twopayment.surcharge_cart_line ||
            !window.twopayment.order_intent_url || !window.twopayment.ajax_token ||
            typeof jQuery === 'undefined') {
            return Promise.resolve({ success: true, changed: false, present: false });
        }

        const seq = ++this._surchargeSyncSeq;
        const requestOnce = () => new Promise((resolve, reject) => {
            jQuery.ajax({
                url: window.twopayment.order_intent_url,
                type: 'POST',
                dataType: 'json',
                timeout: 10000,
                data: {
                    ajax: 1,
                    action: 'syncSurchargeLine',
                    token: window.twopayment.ajax_token,
                    selected: selected ? 1 : 0,
                    // Server-side ordering guard: requests carrying a lower
                    // seq than the last applied one are ignored server-side.
                    seq: seq
                }
            }).done(resolve).fail(reject);
        });

        return requestOnce()
            .catch(() => requestOnce()) // one silent retry on transport failure
            .catch(() => ({ success: false, changed: false, present: false }))
            .then((response) => {
                if (seq !== this._surchargeSyncSeq) {
                    // A newer selection superseded this sync; let it drive the UI.
                    return response;
                }
                if (response && response.success && response.changed) {
                    this.triggerNativeCartRefresh();
                } else if (!response || !response.success) {
                    console.warn('Two Payment: surcharge cart line sync failed; server-side order-create gate remains authoritative.');
                }
                return response;
            });
    }

    /**
     * Trigger PrestaShop core's own cart-refresh pipeline (no hand-rolled
     * summary re-render). Empirically verified against PS8 themes/core.js:
     * core listens on the 'updateCart' event, POSTs the .js-cart
     * data-refresh-url, replaces the summary partials, and - because the
     * payment step carries the .js-cart-payment-step-refresh marker - fully
     * reloads the checkout page (then emits 'updatedCart' post-render). The
     * handler dereferences event.resp.cart unconditionally, so a cart object
     * (prestashop.cart or {}) must always be passed.
     */
    triggerNativeCartRefresh() {
        try {
            const checkedRadio = document.querySelector('input[name="payment-option"]:checked, .payment-options input[type="radio"]:checked');
            if (checkedRadio && checkedRadio.id) {
                sessionStorage.setItem(this._surchargeRestoreKey, checkedRadio.id);
            }
        } catch (e) {
            // sessionStorage unavailable: reload still happens, buyer re-picks manually.
        }

        if (window.prestashop && typeof window.prestashop.emit === 'function') {
            window.prestashop.emit('updateCart', {
                reason: { linkAction: 'twoSurchargeSync' },
                resp: { cart: (window.prestashop && window.prestashop.cart) || {} }
            });
        }
    }

    /**
     * After the native payment-step reload, re-check the payment option the
     * buyer had clicked and re-fire its change event so all listeners
     * (PrestaShop core's option toggling, our own handler) rebuild their
     * state. The server-side sync endpoint is idempotent, so the re-fired
     * change cannot loop: it reports changed=false and no refresh is
     * triggered again.
     */
    restorePaymentSelectionAfterCartRefresh() {
        let radioId = null;
        try {
            radioId = sessionStorage.getItem(this._surchargeRestoreKey);
            if (radioId !== null) {
                sessionStorage.removeItem(this._surchargeRestoreKey);
            }
        } catch (e) {
            return;
        }
        if (!radioId) {
            return;
        }
        const radio = document.getElementById(radioId);
        if (radio && !radio.checked) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
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
        
        return false;
    }

    isPaymentConfirmationButton(target) {
        if (!(target instanceof Element)) {
            return false;
        }

        const button = target.closest('button[type="submit"], input[type="submit"]');
        if (!button) {
            return false;
        }

        // Keep interception strictly scoped to payment confirmation area.
        return !!(
            button.closest('#payment-confirmation') ||
            button.closest('.payment-confirmation')
        );
    }

    isPaymentConfirmationForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return false;
        }

        return !!(
            form.closest('#payment-confirmation') ||
            form.closest('.payment-confirmation')
        );
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
            if (errLower.includes('skipped_no_company')) {
                this.showCompanyRequiredMessage(err, 'no_company');
                return;
            }
            
            if (errLower.includes('skipped')) {
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
                <div class="two-term-chips">
                    <div class="two-term-chips__container" id="two-terms-chips">
                        <!-- Chips will be populated by JavaScript -->
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
        // Try multiple selectors for the chip container
        let termsContainer = document.querySelector('#two-terms-chips');
        if (!termsContainer) {
            termsContainer = document.querySelector('.two-term-chips__container');
        }
        
        let selectedDays = document.querySelector('#two-selected-days');
        if (!selectedDays) {
            selectedDays = document.querySelector('.two-terms-selected-days');
        }
        
        if (!termsContainer) {
            console.error('Two Payment: Terms chip container not found');
            return;
        }
        
        // Check if already initialized with our chips
        if (termsContainer.hasChildNodes() && termsContainer.querySelector('.two-term-chip')) {
            return;
        }
        
        if (termsContainer.hasChildNodes()) {
            // Clear existing content to reinitialize
            termsContainer.innerHTML = '';
        }
        
        // Get payment terms from admin configuration (passed via template)
        const availableTerms = this.config.available_payment_terms;
        const configuredDefaultTerm = this.config.default_payment_term;
        const termType = this.config.payment_term_type || 'STANDARD';

        // If no terms configured, don't show payment terms
        if (!availableTerms || !Array.isArray(availableTerms) || availableTerms.length === 0) {
            return;
        }

        // Guard against a configured default that isn't actually offered — falls back
        // to the first offered term so the chip UI and "Pay in X days" text never
        // point at a term with no selectable chip.
        const defaultTerm = availableTerms.includes(configuredDefaultTerm) ? configuredDefaultTerm : null;

        termsContainer.setAttribute('role', 'radiogroup');
        
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
        
        // A single offered term is applied silently (no selectable chips).
        const singleTerm = availableTerms.length === 1;

        // Tracks the in-flight persist request so a rapid second click aborts the
        // first — otherwise two POSTs can race and the server can persist whichever
        // lands last rather than whichever the buyer clicked last.
        let pendingTermRequest = null;

        const formatChipLabel = (days) => termType === 'EOM'
            ? this.t('end_of_month_plus_days', 'End of Month + %s days').replace('%s', days)
            : days + ' ' + this.t('days', 'days');

        const formatPayInLabel = (days) => {
            const payInText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.pay_in
                ? window.twopayment.i18n.pay_in
                : 'Pay in';
            const daysText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.days
                ? window.twopayment.i18n.days
                : 'days';
            const fromEndOfMonthText = window.twopayment && window.twopayment.i18n && window.twopayment.i18n.from_end_of_month
                ? window.twopayment.i18n.from_end_of_month
                : 'from end of month';

            return termType === 'EOM'
                ? payInText + ' ' + days + ' ' + daysText + ' ' + fromEndOfMonthText
                : payInText + ' ' + days + ' ' + daysText;
        };

        // Create term chips (parity with Magento/WooCommerce chip selector)
        availableTerms.forEach((days, index) => {
            const termChip = document.createElement('button');
            termChip.type = 'button';
            termChip.className = 'two-term-chip' + (singleTerm ? ' two-term-chip--single' : '');
            termChip.setAttribute('role', 'radio');
            termChip.setAttribute('aria-label', formatPayInLabel(days));

            const daysLabel = document.createElement('span');
            daysLabel.className = 'two-term-chip__days';

            // Format display based on term type (EOM+X for End-of-Month, X for Standard)
            if (termType === 'EOM') {
                daysLabel.textContent = 'EOM+' + days;
            } else {
                daysLabel.textContent = days;
            }
            termChip.title = formatChipLabel(days);
            termChip.appendChild(daysLabel);

            // Per-term surcharge slot: starts as a loading indicator (three
            // animated dots, Magento gateway_method.html parity) on EVERY
            // chip, unconditionally. The buyer must never see the configured
            // surcharge RATE — only the real quoted amount for this cart once
            // refreshTermSurchargeAmounts() resolves, or nothing on failure.
            const surchargeLabel = document.createElement('span');
            surchargeLabel.className = 'two-term-chip__surcharge';
            const loadingDots = document.createElement('span');
            loadingDots.className = 'two-term-chip__loading';
            loadingDots.setAttribute('aria-hidden', 'true');
            for (let i = 0; i < 3; i++) {
                const dot = document.createElement('span');
                dot.textContent = '.';
                loadingDots.appendChild(dot);
            }
            surchargeLabel.appendChild(loadingDots);
            termChip.appendChild(surchargeLabel);

            termChip.dataset.days = days;

            // Set default term: use configured default, or if only one term, make it selected, or first term
            const isDefaultTerm = defaultTerm ? (days === defaultTerm) :
                                 (singleTerm ? true : index === 0);

            if (isDefaultTerm) {
                termChip.classList.add('two-term-chip--selected');
            }
            termChip.setAttribute('aria-checked', isDefaultTerm ? 'true' : 'false');

            // A single term is non-selectable; skip the click handler and remove it
            // from the tab order so it doesn't present as a dead interactive control.
            if (singleTerm) {
                termChip.disabled = true;
                termChip.setAttribute('aria-disabled', 'true');
                termsContainer.appendChild(termChip);
                return;
            }

            termChip.addEventListener('click', () => {
                // Remove selected state from all chips
                termsContainer.querySelectorAll('.two-term-chip').forEach(chip => {
                    chip.classList.remove('two-term-chip--selected');
                    chip.setAttribute('aria-checked', 'false');
                });

                // Mark clicked chip as selected
                termChip.classList.add('two-term-chip--selected');
                termChip.setAttribute('aria-checked', 'true');

                // Update selected term display
                if (selectedDays) {
                    selectedDays.textContent = formatPayInLabel(days);
                }

                // Persist selection in cookie via backend (10s timeout). Abort any
                // still-in-flight persist so an out-of-order response can't leave
                // the cookie holding a term the buyer already clicked away from.
                try {
                    if (window.twopayment && window.twopayment.order_intent_url && window.twopayment.ajax_token) {
                        if (pendingTermRequest && pendingTermRequest.abort) {
                            pendingTermRequest.abort();
                        }
                        pendingTermRequest = $.ajax({
                            url: window.twopayment.order_intent_url,
                            type: 'POST',
                            dataType: 'json',
                            data: { ajax: 1, action: 'savePaymentTerm', token: window.twopayment.ajax_token, days: days },
                            timeout: 10000
                        }).done(() => {
                            // The surcharge amount is term-dependent: with Two
                            // selected, re-quote and update the cart line for
                            // the newly persisted term (idempotent server-side;
                            // no-op when the amount is unchanged).
                            if (this.isTwoPaymentSelected()) {
                                this.syncSurchargeCartLine(true);
                            }
                        }).fail((xhr, statusText) => {
                            if (statusText !== 'abort') {
                                console.error('Two Payment: Error saving term:', statusText);
                            }
                        });
                    }
                } catch (e) {
                    console.error('Two Payment: Error saving term:', e);
                }
            });

            termsContainer.appendChild(termChip);
        });
        
        // Set initial selected term display — falls back to the first offered term
        // when no valid default was configured (defaultTerm is null in that case).
        const activeTerm = defaultTerm || availableTerms[0];

        if (selectedDays && activeTerm) {
            selectedDays.textContent = formatPayInLabel(activeTerm);
        }

        // Resolve each chip's loading indicator to the REAL quoted amount for
        // this cart, asynchronously — or to blank if the quote fails.
        this.refreshTermSurchargeAmounts(termsContainer);
    }

    /**
     * Clear every chip's surcharge slot (removes the loading dots) so a
     * failed/absent quote reads as a deliberate empty state, never as a
     * permanently-animating loader.
     */
    clearTermSurchargeLoading(termsContainer) {
        if (!termsContainer) {
            return;
        }
        termsContainer.querySelectorAll('.two-term-chip .two-term-chip__surcharge').forEach((label) => {
            label.textContent = '';
        });
    }

    /**
     * Replace each term chip's loading indicator with the live quoted fee
     * amount for the current cart (server proxies
     * POST /v1/pricing/order/fee per offered term — see
     * getTwoOfferedTermSurchargeAmounts() in twopayment.php). Magento parity:
     * gateway_method.js renders '+' + formatted amount per chip.
     *
     * Fail-soft by design: any failure (network error, non-200, success:false,
     * missing config) clears every chip's surcharge slot to blank — the buyer
     * must never see the configured rate, and a loader that never resolves
     * reads as broken. A zero amount for a term hides that chip's fee text
     * ("no fee" semantics), it is NOT a failure signal. Only the
     * .two-term-chip__surcharge nodes are touched — never the chip's
     * selected/aria state, so a buyer clicking before the fetch resolves is
     * never clobbered.
     */
    refreshTermSurchargeAmounts(termsContainer) {
        try {
            if (!termsContainer) {
                return;
            }
            if (!window.twopayment || !window.twopayment.order_intent_url || !window.twopayment.ajax_token) {
                // Can't quote at all — don't leave the dots animating forever.
                this.clearTermSurchargeLoading(termsContainer);
                return;
            }
            $.ajax({
                url: window.twopayment.order_intent_url,
                type: 'POST',
                dataType: 'json',
                data: { ajax: 1, action: 'fetchTermSurcharges', token: window.twopayment.ajax_token },
                timeout: 10000
            }).done((response) => {
                if (!response || !response.success || !response.amounts) {
                    this.clearTermSurchargeLoading(termsContainer);
                    return;
                }
                // Same amount + space + currency-code composition as the admin
                // merchant-fee display (configuration.tpl) — deliberately plain
                // number formatting, no client-side price-locale guessing.
                const currency = String(response.currency || '').toUpperCase().replace(/^\s+|\s+$/g, '');
                const suffix = currency !== '' ? ' ' + currency : '';
                termsContainer.querySelectorAll('.two-term-chip').forEach((chip) => {
                    const surchargeLabel = chip.querySelector('.two-term-chip__surcharge');
                    if (!surchargeLabel) {
                        return;
                    }
                    const days = chip.dataset.days;
                    const amount = (days && (days in response.amounts)) ? Number(response.amounts[days]) : 0;
                    if (!isFinite(amount) || amount <= 0) {
                        // Zero/invalid/absent quote for THIS term: show no fee
                        // rather than "+0.00" (Magento zero-hide semantics) —
                        // and never leave the loading dots behind.
                        surchargeLabel.textContent = '';
                        return;
                    }
                    surchargeLabel.textContent = '+' + amount.toFixed(2) + suffix;
                });
            }).fail(() => {
                this.clearTermSurchargeLoading(termsContainer);
            });
        } catch (e) {
            // Never let a fee-quote failure break the checkout render — but
            // still clear the loaders so chips don't animate forever.
            this.clearTermSurchargeLoading(termsContainer);
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

    enableTwoPayment() {
        if (this.twoPaymentRadio) {
            this.twoPaymentRadio.disabled = false;
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
            if (this.companySearch && this.companySearch.destroy) {
                this.companySearch.destroy();
                this.companySearch = null;
            }
            this.initializeCompanySearch();
        }

        // Clear cached intent state when address is edited so a new selection can trigger intent
        if (this.orderIntent && this.orderIntent.reset) {
            this.orderIntent.reset();
        }
        this.clearOrderIntentUI();
        this.clearOrderIntentResultFromServer();
        this.enableTwoPayment();

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
        this.detectCheckoutStep();
        if (this.currentStep !== 'payment') {
            return;
        }

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
        
        // Initialize company search for address step
        if (this.config.companySearchEnabled && this.currentStep === 'address') {
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
    /**
     * Initialize order intent module
     */
    initializeOrderIntent() {
        if (!this.orderIntent && window.TwoOrderIntent) {
            // Block submitting the order while Two is selected and the
            // last order-intent came back declined - this used to be
            // conditional on the (now-removed) account-type toggle; there
            // is no longer a reason to ever skip it, so it is unconditional
            // (TwoOrderIntent's own default is also true).
            this.orderIntent = new TwoOrderIntent({
                enabled: true,
                orderIntentUrl: this.config.orderIntentUrl,
                ajaxToken: this.config.ajaxToken,
                enablePaymentPreventionOnDecline: true
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
    }
}

// Export for use in other modules
window.TwoCheckoutManager = TwoCheckoutManager;
