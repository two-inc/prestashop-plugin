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

    t(key, fallback) {
        if (window.twopayment && window.twopayment.i18n && window.twopayment.i18n[key]) {
            return window.twopayment.i18n[key];
        }
        return fallback;
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
        
        // CRITICAL FIX: Always let the backend try to resolve company data
        // The backend can check address fields (dni, vat_number) that the frontend can't see
        // Backend will return appropriate status codes if company data is missing
        return this.collectFormData()
            .then(formData => {
                // Always proceed to backend - it will check:
                // 1. Form data (company, companyid)
                // 2. Session cookie
                // 3. Address fields (dni, vat_number) and verify via Two API
                // Backend returns status codes: 'no_company', 'incomplete_company' if needed
                return this.fetchOrderIntentPayload(formData);
            })
            .then(payload => {
                const payloadCompany = (
                    payload &&
                    payload.buyer &&
                    payload.buyer.company &&
                    payload.buyer.company.company_name
                ) ? String(payload.buyer.company.company_name).trim() : '';
                if (payloadCompany) {
                    this.lastCompany = payloadCompany;
                }
                return this.callTwoOrderIntent(payload);
            })
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

            // Only use cookie fallback when BOTH values are missing (e.g., payment step with no address form fields).
            // If one value exists and the other is missing, keep form values as-is to avoid stale mixed company/companyid pairs.
            if ((!company && !companyid) && window.twopayment && window.twopayment.order_intent_url && window.twopayment.ajax_token) {
                $.ajax({
                    url: window.twopayment.order_intent_url,
                    type: 'POST',
                    dataType: 'json',
                    data: { ajax: 1, action: 'getCompany', token: window.twopayment.ajax_token },
                    timeout: 8000
                }).done((res) => {
                    if (res && res.success) {
                        formData.company = (res.company || '');
                        formData.companyid = (res.companyid || '');
                        // If stored company country/address mismatches current address context, invalidate stored company
                        const addressCountryIso = this.getCurrentAddressCountryISO();
                        const storedCountryMismatch = res.country && addressCountryIso && res.country.toUpperCase() !== addressCountryIso.toUpperCase();
                        const currentAddressId = this.getCurrentAddressId();
                        const storedAddressId = res.address_id ? parseInt(res.address_id, 10) : 0;
                        const storedAddressMismatch = storedAddressId > 0 && currentAddressId > 0 && storedAddressId !== currentAddressId;
                        if (countryChanged || storedCountryMismatch || storedAddressMismatch) {
                            // DEBUG: Log country change details for troubleshooting
                            console.log('Two Order Intent: Invalidating stored company context.', {
                                countryChanged: countryChanged,
                                storedCountryMismatch: storedCountryMismatch,
                                storedAddressMismatch: storedAddressMismatch,
                                storedCompanyCountry: res.country,
                                storedAddressId: storedAddressId,
                                currentAddressId: currentAddressId,
                                currentAddressCountry: addressCountryIso,
                                invalidatingCompany: res.company
                            });
                            formData.company = '';
                            formData.companyid = '';
                        }
                        // Persist last company for messaging
                        this.lastCompany = formData.company;
                    } else {
                        formData.company = company;
                        formData.companyid = companyid;
                    }
                    const selectedAddressId = this.getCurrentAddressId();
                    if (selectedAddressId > 0) {
                        formData.id_address_invoice = selectedAddressId;
                        formData.id_address_delivery = selectedAddressId;
                    }
                    resolve(formData);
                }).fail(() => {
                    formData.company = company;
                    formData.companyid = companyid;
                    const selectedAddressId = this.getCurrentAddressId();
                    if (selectedAddressId > 0) {
                        formData.id_address_invoice = selectedAddressId;
                        formData.id_address_delivery = selectedAddressId;
                    }
                    resolve(formData);
                });
                return;
            }
            formData.company = company;
            formData.companyid = companyid;
            this.lastCompany = company;
            const selectedAddressId = this.getCurrentAddressId();
            if (selectedAddressId > 0) {
                formData.id_address_invoice = selectedAddressId;
                formData.id_address_delivery = selectedAddressId;
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
                crossDomain: true,
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                xhrFields: { withCredentials: false },
                beforeSend: this.buildPublicApiBeforeSend(),
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
            return { success: false, approved: false, message: this.t('invalid_response_from_server', 'Invalid response from server') };
        }
        const result = {
            success: !!response.success,
            approved: !!response.approved,
            message: response.message || (response.approved
                ? this.t('invoice_likely_accepted', 'Your invoice with Two is likely to be accepted')
                : this.t('invoice_cannot_be_approved', 'Your invoice with Two cannot be approved at this time')),
            rawResponse: response.rawResponse || response
        };
        const companyField = document.querySelector("input[name='company']");
        if (!this.lastCompany || (companyField && companyField.value)) {
            this.lastCompany = companyField && companyField.value ? companyField.value : this.lastCompany;
        }
        // Inject company into message immediately to ensure UI gets the contextual string
        if (this.lastCompany && typeof this.lastCompany === 'string' && this.lastCompany.trim().length > 0) {
            const approvedTemplate = this.t('invoice_likely_accepted_for', 'Your invoice with Two is likely to be accepted for %s');
            const declinedTemplate = this.t('invoice_cannot_be_approved_for', 'Your invoice with Two cannot be approved at this time for %s');
            result.message = result.approved
                ? approvedTemplate.replace('%s', this.lastCompany)
                : declinedTemplate.replace('%s', this.lastCompany);
        }
        this.lastResult = result;
        this.updateUI(result);
        return result;
    }

    getErrorMessage(errorString) {
        // Default fallback message (uses i18n)
        const defaultMessage = this.t(
            'invoice_declined',
            'Your invoice with Two cannot be approved at this time. Please select an alternative payment method.'
        );
            
        if (!errorString) {
            return defaultMessage;
        }
        const error = ('' + errorString).toLowerCase();
        
        // Phone number validation errors (priority - specific error type)
        if (error.includes('invalid phone number') || 
            (error.includes('phone_number') && error.includes('value_error'))) {
            return this.t(
                'invalid_phone_number',
                'The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country.'
            );
        }
        
        // Email validation errors
        if (error.includes('invalid email') || 
            (error.includes('email') && error.includes('value_error'))) {
            return this.t('invalid_email', 'The email address provided is invalid. Please check your email and try again.');
        }
        
        // Organization/company errors
        if (error.includes('organization_number') || error.includes('organization number')) {
            return this.t(
                'company_incomplete',
                'Company information is incomplete. Go back to your billing address and select your company from the search results.'
            );
        }
        
        // General validation errors
        if (error.includes('validation error') || error.includes('value_error')) {
            return this.t(
                'validation_error',
                'Some of the information provided is invalid. Please check your billing address details and try again.'
            );
        }
        
        // Invalid data errors
        if (error.includes('invalid')) {
            return this.t(
                'invalid_company',
                'The company information provided is not valid. Go back to your billing address and select your company from the search results.'
            );
        }
        
        // Not found errors
        if (error.includes('not found') || error.includes('404')) {
            return this.t(
                'company_verify_failed',
                'Company information could not be verified. Go back to your billing address and select your company from the search results.'
            );
        }
        
        return defaultMessage;
    }

    handleError(error) {
        // Backend provides clear status codes, so just pass through the error message
        const message = this.getErrorMessage(error && error.message ? error.message : '');
        
        const result = {
            success: false,
            approved: false,
            message: message,
            error: error && error.message ? error.message : '',
            status: 'error'
        };
        this.lastResult = result;
        this.updateUI(result);
        return result;
    }

    updateUI(result) {
        const $twoPaymentOption = $('.payment-option').filter((_, element) => {
            return $(element).find('[data-module-name="twopayment"]').length > 0;
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
                const t = this.t('invoice_likely_accepted_for', 'Your invoice with Two is likely to be accepted for %s');
                messageText = t.replace('%s', this.lastCompany);
            } else {
                const t = this.t('invoice_cannot_be_approved_for', 'Your invoice with Two cannot be approved at this time for %s');
                messageText = t.replace('%s', this.lastCompany);
            }
        }

        $messageContainer
            .removeClass('approved declined loading')
            .addClass(result.approved ? 'approved' : 'declined')
            .text(messageText);
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
        const message = this.lastResult
            ? this.lastResult.message
            : this.t('resolve_payment_issue_before_continuing', 'Please resolve the payment issue before continuing.');
        const $twoPaymentOption = $('.payment-option').filter((_, element) => {
            return $(element).find('[data-module-name="twopayment"]').length > 0;
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
            const $twoPaymentOption = $('.payment-option').filter((_, element) => {
                return $(element).find('[data-module-name="twopayment"]').length > 0;
            });
            if ($twoPaymentOption.length > 0 && $twoPaymentOption.is(':visible')) {
                // Check for country change - if country changed, user needs to re-select company
                let countryChanged = false;
                try { countryChanged = (sessionStorage.getItem('two_country_changed') === '1'); } catch (e) {}
                
                if (countryChanged) {
                    // Clear country changed flag
                    try { sessionStorage.removeItem('two_country_changed'); } catch (e) {}
                    const $msg = $twoPaymentOption.find('.two-order-intent-message');
                    if ($msg.length > 0) {
                        const t = this.t(
                            'select_company_to_use_two',
                            'To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'
                        );
                        $msg.removeClass('approved declined loading').text(t).show();
                    }
                    return;
                }
                
                // CRITICAL FIX: Always let the backend check for company data
                // Backend can find org numbers in address fields (dni, vat_number) 
                // that the frontend can't see, and verify them via Two API
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
    reset() {
        this.lastResult = null;
        this.lastCompany = null;
        this.isProcessing = false;
        this.stopMonitoring();
    }
}

window.TwoOrderIntent = TwoOrderIntent;
