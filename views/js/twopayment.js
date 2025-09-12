/**
 * Two Payment Module - Production Ready
 * Clean initialization with minimal logging
 */

$(document).ready(function() {
    'use strict';
    
    // Check if twopayment configuration is available
    if (typeof twopayment === 'undefined') {
        return;
    }
    
    // Initialize the checkout manager with configuration
    const checkoutManager = new TwoCheckoutManager({
        companySearchEnabled: twopayment.company_name_search === '1',
        orderIntentEnabled: twopayment.enable_order_intent === '1',
        checkoutHost: twopayment.checkout_host,
        orderIntentUrl: twopayment.order_intent_url,
        ajaxToken: twopayment.ajax_token,
        available_payment_terms: twopayment.available_payment_terms || [30],
        default_payment_term: twopayment.default_payment_term || 30
    });
    
    // Store global reference for modules
    window.TwoCheckoutManager_Instance = checkoutManager;
});
