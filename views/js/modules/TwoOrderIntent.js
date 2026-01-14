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
        this.lastCompany = null;
    }
    
    shouldRunOrderIntent() {
        if (!this.config.enabled) return false;
        if (!this.config.orderIntentUrl || !this.config.ajaxToken) return false;
        if (this.isProcessing) return false;
        return true;
    }
    
    checkOrderIntent() {
        if (!this.shouldRunOrderIntent()) {
            return Promise.resolve(this.lastResult || { success: false, error: 'Order intent check skipped' });
        }
        this.isProcessing = true;
        
        return this.collectFormData()
            .then(formData => {
                const useAccountType = !!(window.twopayment && String(window.twopayment.use_account_type) === '1');
                if (!useAccountType) {
                    const hasCompany = !!(formData.company && String(formData.company).trim().length > 0);
                    const hasCompanyId = !!(formData.companyid && String(formData.companyid).trim().length > 0);
                    if (!hasCompany || !hasCompanyId) {
                        // Skip without calling server; UI will prompt
                        throw new Error('skipped');
                    }
                }
                return this.fetchOrderIntentPayload(formData);
            })
            .then(payload => this.callTwoOrderIntent(payload))
            .then(result => this.processResult(result))
            .catch(error => this.handleError(error))
            .finally(() => { this.isProcessing = false; });
    }
    
    collectFormData() {
        return new Promise((resolve) => {
            const formData = {
                ajax: 1,
                action: 'checkOrderIntent',
                token: this.config.ajaxToken,
                account_type: 'business'
            };
            const companyField = document.querySelector("input[name='company']");
            const companyIdField = document.querySelector("input[name='companyid']");
            let company = companyField ? (companyField.value || '') : '';
            let companyid = companyIdField ? (companyIdField.value || '') : '';

            // If country changed since last selection, invalidate any existing values until a new selection is made
            let countryChanged = false;
            try { countryChanged = (sessionStorage.getItem('two_country_changed') === '1'); } catch (e) {}
            if (countryChanged) {
                company = '';
                companyid = '';
                // CRITICAL FIX: Clear the flag after handling country change to prevent it from persisting
                try { sessionStorage.removeItem('two_country_changed'); } catch (e) {}
            }

            // If fields are empty (e.g., only payment step visible), try cookie fallback
            if ((!company || !companyid) && window.twopayment && window.twopayment.order_intent_url && window.twopayment.ajax_token) {
                $.ajax({
                    url: window.twopayment.order_intent_url,
                    type: 'POST',
                    dataType: 'json',
                    data: { ajax: 1, action: 'getCompany', token: window.twopayment.ajax_token },
                    timeout: 8000
                }).done((res) => {
                    if (res && res.success) {
                        formData.company = company || (res.company || '');
                        formData.companyid = companyid || (res.companyid || '');
                        // If stored company country mismatches address country or country changed, invalidate stored company
                        const addressCountryIso = this.getCurrentAddressCountryISO();
                        const storedCountryMismatch = res.country && addressCountryIso && res.country.toUpperCase() !== addressCountryIso.toUpperCase();
                        if (countryChanged || storedCountryMismatch) {
                            // DEBUG: Log country change details for troubleshooting
                            console.log('Two Order Intent: Country change detected.', {
                                countryChanged: countryChanged,
                                storedCountryMismatch: storedCountryMismatch,
                                storedCompanyCountry: res.country,
                                currentAddressCountry: addressCountryIso,
                                invalidatingCompany: res.company
                            });
                            formData.company = company; // keep whatever is in the field (likely empty)
                            formData.companyid = companyid;
                        }
                        // Persist last company for messaging
                        this.lastCompany = formData.company;
                    } else {
                        formData.company = company;
                        formData.companyid = companyid;
                    }
                    const addressDeliveryField = document.querySelector("input[name='id_address_delivery']");
                    if (addressDeliveryField) {
                        formData.id_address_delivery = addressDeliveryField.value;
                    }
                    resolve(formData);
                }).fail(() => {
                    formData.company = company;
                    formData.companyid = companyid;
                    const addressDeliveryField = document.querySelector("input[name='id_address_delivery']");
                    if (addressDeliveryField) {
                        formData.id_address_delivery = addressDeliveryField.value;
                    }
                    resolve(formData);
                });
                return;
            }
            formData.company = company;
            formData.companyid = companyid;
            this.lastCompany = company;
            const addressDeliveryField = document.querySelector("input[name='id_address_delivery']");
            if (addressDeliveryField) {
                formData.id_address_delivery = addressDeliveryField.value;
            }
            resolve(formData);
        });
    }

    getCurrentAddressCountryISO() {
        try {
            // ENHANCED: Try multiple country field selectors for better theme compatibility
            const countrySelectors = [
                "select[name='id_country']",
                "select[name='country']", 
                "#id_country",
                ".js-country",
                "select.country"
            ];
            
            let countryField = null;
            for (const selector of countrySelectors) {
                countryField = document.querySelector(selector);
                if (countryField && countryField.selectedOptions && countryField.selectedOptions.length > 0) {
                    break;
                }
            }
            
            if (countryField && countryField.selectedOptions.length > 0) {
                const selectedOption = countryField.selectedOptions[0];
                // Try multiple attribute patterns for ISO code
                const iso = selectedOption.getAttribute('data-iso-code') || 
                           selectedOption.getAttribute('data-iso') ||
                           selectedOption.getAttribute('data-country-iso');
                if (iso) return iso.toUpperCase();
                
                // Fallback: try to get ISO from value if it's a 2-letter code
                const value = countryField.value;
                if (value && value.length === 2 && /^[A-Z]{2}$/i.test(value)) {
                    return value.toUpperCase();
                }
            }
            
            // Additional fallback: check if there's a country ISO in the twopayment configuration
            if (window.twopayment && window.twopayment.countries && countryField) {
                const countryId = countryField.value;
                const isoFromConfig = window.twopayment.countries[countryId];
                if (isoFromConfig) {
                    return isoFromConfig.toUpperCase();
                }
            }
        } catch (e) {
            console.warn('Two Order Intent: Failed to get current address country ISO:', e);
        }
        return '';
    }

    fetchOrderIntentPayload(formData) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: this.config.orderIntentUrl,
                type: 'POST',
                dataType: 'json',
                data: formData,
                timeout: 15000,
                success: (response) => {
                    if (response && response.success && response.payload) {
                        resolve(response.payload);
                    } else if (response && response.error) {
                        reject(new Error(response.error));
                    } else {
                        reject(new Error('Invalid payload response'));
                    }
                },
                error: (xhr, status, error) => {
                    reject(new Error(`Payload build failed: ${error}`));
                }
            });
        });
    }

    callTwoOrderIntent(payload) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: (window.twopayment && window.twopayment.checkout_host ? window.twopayment.checkout_host : '') + '/v1/order_intent',
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                timeout: 15000,
                success: (response) => {
                    // Normalize to previous result shape
                    if (response && typeof response === 'object') {
                        const isApproved = !!response.approved;
                        resolve({ success: true, approved: isApproved, message: '' , rawResponse: response});
                    } else {
                        reject(new Error('Invalid response from Two'));
                    }
                },
                error: (xhr, status, error) => {
                    reject(new Error(`Two order intent failed: ${error}`));
                }
            });
        });
    }

    processResult(response) {
        if (!response || typeof response !== 'object') {
            return { success: false, approved: false, message: 'Invalid response from server' };
        }
        const result = {
            success: !!response.success,
            approved: !!response.approved,
            message: response.message || (response.approved ? 'Your invoice with Two is likely to be accepted' : 'Your invoice with Two cannot be approved at this time'),
            rawResponse: response.rawResponse || response
        };
        const companyField = document.querySelector("input[name='company']");
        if (!this.lastCompany || (companyField && companyField.value)) {
            this.lastCompany = companyField && companyField.value ? companyField.value : this.lastCompany;
        }
        // Inject company into message immediately to ensure UI gets the contextual string
        if (this.lastCompany && typeof this.lastCompany === 'string' && this.lastCompany.trim().length > 0) {
            result.message = result.approved
                ? `Your invoice with Two is likely to be accepted for ${this.lastCompany}`
                : `Your invoice with Two cannot be approved at this time for ${this.lastCompany}`;
        }
        this.lastResult = result;
        this.updateUI(result);
        return result;
    }

    getErrorMessage(errorString) {
        if (!errorString) {
            return 'Your invoice with Two cannot be approved at this time. Please select an alternative payment method.';
        }
        const error = ('' + errorString).toLowerCase();
        
        // Phone number validation errors (priority - specific error type)
        if (error.includes('invalid phone number') || 
            (error.includes('phone_number') && error.includes('value_error'))) {
            return window.twopayment?.i18n?.invalid_phone_number || 
                'The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country.';
        }
        
        // Email validation errors
        if (error.includes('invalid email') || 
            (error.includes('email') && error.includes('value_error'))) {
            return 'The email address provided is invalid. Please check your email and try again.';
        }
        
        // Organization/company errors
        if (error.includes('organization_number') || error.includes('organization number')) {
            return 'Company information is incomplete. Go back to your billing address and select your company from the search results.';
        }
        
        // General validation errors
        if (error.includes('validation error') || error.includes('value_error')) {
            return 'Some of the information provided is invalid. Please check your billing address details and try again.';
        }
        
        // Invalid data errors
        if (error.includes('invalid')) {
            return 'The company information provided is not valid. Go back to your billing address and select your company from the search results.';
        }
        
        // Not found errors
        if (error.includes('not found') || error.includes('404')) {
            return 'Company information could not be verified. Go back to your billing address and select your company from the search results.';
        }
        
        return 'Your invoice with Two cannot be approved at this time. Please select an alternative payment method.';
    }

    handleError(error) {
        const isSkip = (error && typeof error.message === 'string' && error.message.toLowerCase().includes('skipped'));
        const result = {
            success: false,
            approved: false,
            message: isSkip ? 'Order intent check skipped' : this.getErrorMessage(error && error.message ? error.message : ''),
            error: error && error.message ? error.message : ''
        };
        this.lastResult = result;
        this.updateUI(result);
        return result;
    }

    updateUI(result) {
        const $twoPaymentOption = $('.payment-option').filter(function() {
            return $(this).find('[data-module-name="twopayment"]').length > 0;
        });
        if ($twoPaymentOption.length === 0) return;
        let $messageContainer = $twoPaymentOption.find('.two-order-intent-message');
        if ($messageContainer.length === 0) {
            $messageContainer = $('<div class="two-order-intent-message"></div>');
            $twoPaymentOption.find('.payment-option-content, .payment-form, .additional-information').append($messageContainer);
        }
        let messageText = result.message;
        if (this.lastCompany && typeof this.lastCompany === 'string' && this.lastCompany.trim().length > 0) {
            if (result.approved) {
                const t = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.invoice_likely_accepted_for) || 'Your invoice with Two is likely to be accepted for %s';
                messageText = t.replace('%s', this.lastCompany);
            } else {
                const t = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.invoice_cannot_be_approved_for) || 'Your invoice with Two cannot be approved at this time for %s';
                messageText = t.replace('%s', this.lastCompany);
            }
        }

        $messageContainer
            .removeClass('approved declined loading')
            .addClass(result.approved ? 'approved' : 'declined')
            .html(messageText);
        if (result.approved) {
            $twoPaymentOption.removeClass('disabled');
            $twoPaymentOption.find('input[type="radio"]').prop('disabled', false);
        }
        if (this.config.enablePaymentPreventionOnDecline && !result.approved) {
            this.setupOrderPrevention();
        }
    }

    setupOrderPrevention() {
        // Respect config: do not prevent order globally when account type is disabled
        if (!this.config.enablePaymentPreventionOnDecline) {
            $(document).off('submit.twoOrderPrevention');
            $('.btn[type="submit"]').off('click.twoOrderPrevention');
            return;
        }
        $(document).off('submit.twoOrderPrevention');
        $('.btn[type="submit"]').off('click.twoOrderPrevention');
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
    }

    shouldPreventOrder() {
        const $selectedPayment = $('input[name="payment-option"]:checked');
        const isTwoSelected = $selectedPayment.closest('[data-module-name="twopayment"]').length > 0;
        if (!isTwoSelected) return false;
        return this.lastResult && !this.lastResult.approved;
    }

    showOrderPreventionMessage() {
        const message = this.lastResult ? this.lastResult.message : 'Please resolve the payment issue before continuing.';
        const $twoPaymentOption = $('.payment-option').filter(function() {
            return $(this).find('[data-module-name="twopayment"]').length > 0;
        });
        $twoPaymentOption.addClass('pulse-highlight');
        setTimeout(() => { $twoPaymentOption.removeClass('pulse-highlight'); }, 2000);
        if (window.prestashop && window.prestashop.notification) {
            window.prestashop.notification.showNotification(message, 'warning');
        } else {
            alert(message);
        }
    }

    startMonitoring() {
        if (this.checkIntervalId) this.stopMonitoring();
        this.checkIntervalId = setInterval(() => {
            const $twoPaymentOption = $('.payment-option').filter(function() {
                return $(this).find('[data-module-name="twopayment"]').length > 0;
            });
            if ($twoPaymentOption.length > 0 && $twoPaymentOption.is(':visible')) {
                // If account type is disabled and company data is missing, show gentle prompt instead of calling intent
                const useAccountType = !!(window.twopayment && String(window.twopayment.use_account_type) === '1');
                if (!useAccountType) {
                    let countryChanged = false;
                    try { countryChanged = (sessionStorage.getItem('two_country_changed') === '1'); } catch (e) {}
                    const companyField = document.querySelector("input[name='company']");
                    const companyIdField = document.querySelector("input[name='companyid']");
                    const hasCompany = companyField && companyField.value && companyField.value.trim().length > 0;
                    const hasCompanyId = companyIdField && companyIdField.value && companyIdField.value.trim().length > 0;
                    if (countryChanged || !hasCompany || !hasCompanyId) {
                        // ADDITIONAL FIX: Clear country changed flag here as well when detected
                        if (countryChanged) {
                            try { sessionStorage.removeItem('two_country_changed'); } catch (e) {}
                        }
                        const $msg = $twoPaymentOption.find('.two-order-intent-message');
                        if ($msg.length > 0) {
                            const t = (window.twopayment && window.twopayment.i18n && window.twopayment.i18n.select_company_to_use_two) || 'To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.';
                            $msg.removeClass('approved declined loading').html(t).show();
                        }
                        return;
                    }
                }
                this.checkOrderIntent();
            }
        }, 5000);
    }

    stopMonitoring() {
        if (this.checkIntervalId) {
            clearInterval(this.checkIntervalId);
            this.checkIntervalId = null;
        }
    }

    getLastResult() { return this.lastResult; }
    reset() { this.lastResult = null; this.isProcessing = false; this.stopMonitoring(); }
}

window.TwoOrderIntent = TwoOrderIntent;

