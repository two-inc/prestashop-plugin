/**
 * Two Order Intent Module - Clean order intent handling
 * Handles order intent API calls, result processing, and UI updates
 */
class TwoOrderIntent {
    constructor(config) {
        this.config = {
            enabled: false,
            orderIntentUrl: '',
            ajaxToken: '',
            enablePaymentPreventionOnDecline: true,
            ...config
        };
        
        this.lastResult = null;
        this.isProcessing = false;
        this.checkIntervalId = null;
    }
    
    /**
     * Check if order intent should run
     */
    shouldRunOrderIntent() {
        if (!this.config.enabled) {
            return false;
        }
        
        if (!this.config.orderIntentUrl || !this.config.ajaxToken) {
            return false;
        }
        
        if (this.isProcessing) {
            return false;
        }
        
        
        return true;
    }
    
    /**
     * Run order intent check
     */
    checkOrderIntent() {
        if (!this.shouldRunOrderIntent()) {
            return Promise.resolve(this.lastResult || { success: false, error: 'Order intent check skipped' });
        }
        
        this.isProcessing = true;
        
        return this.collectFormData()
            .then(formData => this.callOrderIntentAPI(formData))
            .then(result => this.processResult(result))
            .catch(error => this.handleError(error))
            .finally(() => {
                this.isProcessing = false;
            });
    }
    
    /**
     * Collect form data for order intent - PRESTASHOP NATIVE with session fallback
     */
    collectFormData() {
        return new Promise((resolve) => {
            const formData = {
                ajax: 1,
                action: 'checkOrderIntent',
                token: this.config.ajaxToken,
                account_type: 'business' // Always business for Two payments
            };
            
            // Try to get company data from DOM first
            let companyData = this.getCompanyDataFromDOM();
            
            // Fallback to session storage if DOM data not available or incomplete
            if ((!companyData.company || !companyData.companyid)) {
                const sessionData = this.getCompanyDataFromSession();
                if (sessionData) {
                    companyData.company = companyData.company || sessionData.company;
                    companyData.companyid = companyData.companyid || sessionData.companyid;
                }
            }
            
            formData.company = companyData.company || '';
            formData.companyid = companyData.companyid || '';
            
            // Get address delivery ID if available
            const addressDeliveryField = document.querySelector("input[name='id_address_delivery']");
            if (addressDeliveryField) {
                formData.id_address_delivery = addressDeliveryField.value;
            }
            
            // Form data collected for order intent
            
            resolve(formData);
        });
    }

    /**
     * Get company data from DOM elements
     */
    getCompanyDataFromDOM() {
        const companyField = document.querySelector("input[name='company']");
        const companyIdField = document.querySelector("input[name='companyid']");
        
        const data = {
            company: companyField ? companyField.value || '' : '',
            companyid: companyIdField ? companyIdField.value || '' : ''
        };
        
        if (data.company && data.companyid) {
            data.source = 'DOM';
        }
        
        return data;
    }

    /**
     * Get company data from session storage (fallback)
     */
    getCompanyDataFromSession() {
        try {
            const stored = sessionStorage.getItem('two_company_data');
            if (stored) {
                const data = JSON.parse(stored);
                // Check if data is not too old (30 minutes)
                if (Date.now() - data.timestamp < 1800000) {
                    return {
                        company: data.company || '',
                        companyid: data.companyid || '',
                        source: 'session'
                    };
                }
            }
        } catch (e) {
            console.error('TwoOrderIntent: Error retrieving company data from session:', e);
        }
        return null;
    }
    
    /**
     * Call the order intent API
     */
    callOrderIntentAPI(formData) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: this.config.orderIntentUrl,
                type: 'POST',
                dataType: 'json',
                data: formData,
                timeout: 15000,
                success: (response) => {
                    resolve(response);
                },
                error: (xhr, status, error) => {
                    reject(new Error(`Order intent API failed: ${error}`));
                }
            });
        });
    }
    
    /**
     * Process order intent API result
     */
    processResult(response) {
        if (!response || typeof response !== 'object') {
            return { success: false, error: 'Invalid response from server' };
        }
        
        let result = {
            success: response.success || false,
            approved: false,
            message: '',
            rawResponse: response
        };
        
        if (response.success === true) {
            result.approved = response.approval === true;
            result.message = response.message || (result.approved ? 
                'Your invoice with Two is likely to be accepted' : 
                'Your invoice with Two cannot be approved at this time');
        } else {
            result.approved = false;
            result.message = this.getErrorMessage(response.error);
        }
        
        this.lastResult = result;
        this.updateUI(result);
        
        return result;
    }
    
    /**
     * Convert technical errors to user-friendly messages
     */
    getErrorMessage(errorString) {
        if (!errorString) {
            return 'Your invoice with Two cannot be approved at this time. Please select an alternative payment method.';
        }
        
        const error = errorString.toLowerCase();
        
        if (error.includes('organization_number') || error.includes('organization number')) {
            return 'Company information is incomplete. Please ensure you have selected your company from the search results.';
        } else if (error.includes('validation error')) {
            return 'Some required company information is missing. Please complete all required fields.';
        } else if (error.includes('invalid')) {
            return 'The company information provided is not valid. Please select your company from the search results.';
        } else if (error.includes('not found') || error.includes('404')) {
            return 'Company information could not be verified. Please select your company from the search results.';
        }
        
        return 'Your invoice with Two cannot be approved at this time. Please select an alternative payment method.';
    }
    
    /**
     * Handle API errors
     */
    handleError(error) {
        console.error('TwoOrderIntent: Error occurred:', error);
        
        const result = {
            success: false,
            approved: false,
            message: 'Unable to verify your invoice eligibility at this time. Please try again or select an alternative payment method.',
            error: error.message
        };
        
        this.lastResult = result;
        this.updateUI(result);
        
        return result;
    }
    
    /**
     * Update UI based on order intent result
     */
    updateUI(result) {
        // Find Two payment method elements
        const $twoPaymentOption = $('.payment-option').filter(function() {
            return $(this).find('[data-module-name="twopayment"]').length > 0;
        });
        
        if ($twoPaymentOption.length === 0) {
            return;
        }
        
        // Find or create message container
        let $messageContainer = $twoPaymentOption.find('.two-order-intent-message');
        if ($messageContainer.length === 0) {
            $messageContainer = $('<div class="two-order-intent-message"></div>');
            $twoPaymentOption.find('.payment-option-content, .payment-form, .additional-information').append($messageContainer);
        }
        
        // Update message and styling
        $messageContainer
            .removeClass('approved declined loading')
            .addClass(result.approved ? 'approved' : 'declined')
            .html(result.message);
        
        // Update payment option availability
        if (result.approved) {
            $twoPaymentOption.removeClass('disabled');
            $twoPaymentOption.find('input[type="radio"]').prop('disabled', false);
        }
        
        // Setup order prevention if configured
        if (this.config.enablePaymentPreventionOnDecline && !result.approved) {
            this.setupOrderPrevention();
        }
        
        // UI updated with order intent result
    }
    
    /**
     * Setup order prevention for declined payments
     */
    setupOrderPrevention() {
        // Remove existing prevention handlers
        $(document).off('submit.twoOrderPrevention');
        $('.btn[type="submit"]').off('click.twoOrderPrevention');
        
        // Add prevention handlers
        $(document).on('submit.twoOrderPrevention', 'form', (e) => {
            if (this.shouldPreventOrder()) {
                e.preventDefault();
                this.showOrderPreventionMessage();
                return false;
            }
        });
        
        $('.btn[type="submit"]').on('click.twoOrderPrevention', (e) => {
            if (this.shouldPreventOrder()) {
                e.preventDefault();
                this.showOrderPreventionMessage();
                return false;
            }
        });
        
        // Order prevention set up for declined payment
    }
    
    /**
     * Check if order should be prevented
     */
    shouldPreventOrder() {
        // Check if Two payment is selected
        const $selectedPayment = $('input[name="payment-option"]:checked');
        const isTwoSelected = $selectedPayment.closest('[data-module-name="twopayment"]').length > 0;
        
        if (!isTwoSelected) {
            return false; // Different payment method selected, allow order
        }
        
        // Check if last result was declined
        return this.lastResult && !this.lastResult.approved;
    }
    
    /**
     * Show order prevention message
     */
    showOrderPreventionMessage() {
        const message = this.lastResult ? this.lastResult.message : 'Please resolve the payment issue before continuing.';
        
        // Add visual feedback
        const $twoPaymentOption = $('.payment-option').filter(function() {
            return $(this).find('[data-module-name="twopayment"]').length > 0;
        });
        
        $twoPaymentOption.addClass('pulse-highlight');
        setTimeout(() => {
            $twoPaymentOption.removeClass('pulse-highlight');
        }, 2000);
        
        // Show alert or notification
        if (window.prestashop && window.prestashop.notification) {
            window.prestashop.notification.showNotification(message, 'warning');
        } else {
            alert(message);
        }
        
            // Order prevented due to declined payment
    }
    
    /**
     * Start monitoring for order intent checks
     */
    startMonitoring() {
        if (this.checkIntervalId) {
            this.stopMonitoring();
        }
        
        this.checkIntervalId = setInterval(() => {
            // Check if we're in the right context for order intent
            const $twoPaymentOption = $('.payment-option').filter(function() {
                return $(this).find('[data-module-name="twopayment"]').length > 0;
            });
            
            if ($twoPaymentOption.length > 0 && $twoPaymentOption.is(':visible')) {
                this.checkOrderIntent();
            }
        }, 5000); // Check every 5 seconds
        
        // Monitoring started
    }
    
    /**
     * Stop monitoring
     */
    stopMonitoring() {
        if (this.checkIntervalId) {
            clearInterval(this.checkIntervalId);
            this.checkIntervalId = null;
            // Monitoring stopped
        }
    }
    
    /**
     * Get last result
     */
    getLastResult() {
        return this.lastResult;
    }
    
    /**
     * Reset state
     */
    reset() {
        this.lastResult = null;
        this.isProcessing = false;
        this.stopMonitoring();
        // State reset
    }
}

// Export for use in other modules
window.TwoOrderIntent = TwoOrderIntent;
