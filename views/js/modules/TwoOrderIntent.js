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
            .then(formData => this.fetchOrderIntentPayload(formData))
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
            // If fields are empty (e.g., only payment step visible), try cookie fallback
            if ((!company || !companyid) && window.twopayment && window.twopayment.order_intent_url && window.twopayment.ajax_token) {
                $.ajax({
                    url: window.twopayment.order_intent_url,
                    type: 'POST',
                    dataType: 'json',
                    data: { ajax: 1, action: 'getCompany', token: window.twopayment.ajax_token },
                    timeout: 8000
                }).then((res) => {
                    if (res && res.success) {
                        formData.company = company || (res.company || '');
                        formData.companyid = companyid || (res.companyid || '');
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
                }).catch(() => {
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
        if (this.lastCompany && this.lastCompany.trim().length > 0) {
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
        if (error.includes('organization_number') || error.includes('organization number')) {
            return 'Company information is incomplete. Please ensure you have selected your company from the search results.';
        }
        if (error.includes('validation error')) {
            return 'Some required company information is missing. Please complete all required fields.';
        }
        if (error.includes('invalid')) {
            return 'The company information provided is not valid. Please select your company from the search results.';
        }
        if (error.includes('not found') || error.includes('404')) {
            return 'Company information could not be verified. Please select your company from the search results.';
        }
        return 'Your invoice with Two cannot be approved at this time. Please select an alternative payment method.';
    }

    handleError(error) {
        const result = {
            success: false,
            approved: false,
            message: this.getErrorMessage(error && error.message ? error.message : ''),
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
                messageText = `Your invoice with Two is likely to be accepted for ${this.lastCompany}`;
            } else {
                messageText = `Your invoice with Two cannot be approved at this time for ${this.lastCompany}`;
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

