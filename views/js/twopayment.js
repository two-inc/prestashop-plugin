/**
 * Two Payment Module - Production Ready with Enhanced Compatibility
 * Defensive initialization for merchant environment compatibility
 */

// CRITICAL FIX: Ensure jQuery is available before executing
(function() {
    'use strict';
    
    // Wait for jQuery to be available (with timeout)
    function waitForJQuery(callback, maxAttempts = 50) {
        if (typeof jQuery !== 'undefined' && typeof $ !== 'undefined') {
            // jQuery is available, proceed
            callback();
        } else if (maxAttempts > 0) {
            // jQuery not yet available, wait and retry
            setTimeout(function() {
                waitForJQuery(callback, maxAttempts - 1);
            }, 100);
        } else {
            // jQuery failed to load after timeout
            console.error('Two Payment: jQuery not available after timeout. Module cannot initialize.');
        }
    }
    
    // Initialize when jQuery is ready
    waitForJQuery(function() {
        $(document).ready(function() {
    
    // DEFENSIVE: Guard against missing configuration
    if (typeof twopayment === 'undefined') {
        console.warn('Two Payment: Configuration not found. Module may not be properly loaded.');
        return;
    }
    
    // DEFENSIVE: Guard against missing dependencies
    if (typeof TwoCheckoutManager === 'undefined') {
        console.error('Two Payment: TwoCheckoutManager class not found. Check script loading order.');
        return;
    }
    
    // DEFENSIVE: Retry initialization if DOM isn't ready
    function initializeTwoPayment() {
        try {
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
            
            // ADDITIONAL FIX: Ensure phone validation initializes on address forms
            if (typeof TwoPhoneValidation !== 'undefined') {
                // Initialize immediately if phone field exists
                const phoneField = document.querySelector("input[name='phone'], input[name='phone_mobile']");
                if (phoneField && !phoneField.hasAttribute('data-intl-tel-input-id')) {
                    try {
                        new TwoPhoneValidation();
                    } catch (e) {
                        console.warn('Two Payment: Phone validation initialization failed:', e);
                    }
                }
            }
            
        } catch (error) {
            console.error('Two Payment: Initialization failed:', error);
            
            // Fallback: Try again after short delay for theme compatibility
            setTimeout(() => {
                try {
                    if (typeof TwoCheckoutManager !== 'undefined') {
                        const checkoutManager = new TwoCheckoutManager({
                            companySearchEnabled: twopayment.company_name_search === '1',
                            orderIntentEnabled: twopayment.enable_order_intent === '1',
                            checkoutHost: twopayment.checkout_host,
                            orderIntentUrl: twopayment.order_intent_url,
                            ajaxToken: twopayment.ajax_token,
                            available_payment_terms: twopayment.available_payment_terms || [30],
                            default_payment_term: twopayment.default_payment_term || 30
                        });
                        window.TwoCheckoutManager_Instance = checkoutManager;
                    }
                } catch (retryError) {
                    console.error('Two Payment: Retry initialization also failed:', retryError);
                }
            }, 1000);
        }
    }
    
    // Initialize immediately
    initializeTwoPayment();
    
    // ADDITIONAL COMPATIBILITY: Listen for dynamic content changes (some themes load checkout content via AJAX)
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver((mutations) => {
            let shouldReinit = false;
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    for (let node of mutation.addedNodes) {
                        if (node.nodeType === 1 && 
                            (node.querySelector && 
                             (node.querySelector('.payment-options') || 
                              node.querySelector("input[name='phone']") ||
                              node.querySelector('.js-address-form')))) {
                            shouldReinit = true;
                            break;
                        }
                    }
                }
            });
            
            if (shouldReinit && typeof TwoCheckoutManager !== 'undefined' && !window.TwoCheckoutManager_Instance) {
                setTimeout(initializeTwoPayment, 100);
            }
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    }
        }); 
    }); 
})(); 
