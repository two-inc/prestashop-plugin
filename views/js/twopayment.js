/**
 * Two Payment Module - Production Ready with Enhanced Compatibility
 * Defensive initialization for merchant environment compatibility
 */

// CRITICAL FIX: Ensure jQuery is available before executing
(function() {
    'use strict';
    let localFallbackAttempted = false;

    function getLocalJQueryCandidates() {
        const candidates = [];
        const base = (
            window.prestashop &&
            window.prestashop.urls &&
            window.prestashop.urls.base_url
        ) ? window.prestashop.urls.base_url : '/';

        const normalizedBase = base.endsWith('/') ? base : (base + '/');
        const versions = ['3.7.1', '3.6.0', '3.5.1', '2.2.4', '1.11.0'];

        versions.forEach(function(version) {
            candidates.push(normalizedBase + 'js/jquery/jquery-' + version + '.min.js');
            candidates.push(normalizedBase + 'js/jquery/jquery-' + version + '.js');
        });

        return candidates;
    }

    function loadScriptSequentially(urls, done) {
        if (!urls.length) {
            done(false);
            return;
        }

        const url = urls.shift();
        const script = document.createElement('script');
        script.src = url;
        script.async = false;
        script.onload = function() {
            done(true);
        };
        script.onerror = function() {
            loadScriptSequentially(urls, done);
        };
        document.head.appendChild(script);
    }

    function ensureLocalJQueryFallback(callback) {
        if (localFallbackAttempted) {
            callback();
            return;
        }

        localFallbackAttempted = true;
        loadScriptSequentially(getLocalJQueryCandidates(), function() {
            callback();
        });
    }

    // Wait for jQuery to be available (with timeout)
    function waitForJQuery(callback, maxAttempts = 50) {
        if (typeof jQuery !== 'undefined' && typeof $ !== 'undefined') {
            // jQuery is available, proceed
            callback();
        } else if (maxAttempts > 0) {
            if (maxAttempts === 25 && !localFallbackAttempted) {
                ensureLocalJQueryFallback(function() {
                    setTimeout(function() {
                        waitForJQuery(callback, maxAttempts - 1);
                    }, 100);
                });
                return;
            }
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
            if (window.TwoCheckoutManager_Instance && typeof window.TwoCheckoutManager_Instance.cleanup === 'function') {
                window.TwoCheckoutManager_Instance.cleanup();
            }

            // Initialize the checkout manager with configuration
            const checkoutManager = new TwoCheckoutManager({
                companySearchEnabled: twopayment.company_name_search === '1',
                // Separate toggle from companySearchEnabled (TWO-25203): the
                // search widget can be on while the address / DNI / VAT fill
                // is off. Absent reads as enabled, matching the server-side
                // default-on resolver.
                addressLookupEnabled: twopayment.address_lookup !== '0',
                orderIntentEnabled: true,
                checkoutHost: twopayment.checkout_host,
                orderIntentUrl: twopayment.order_intent_url,
                ajaxToken: twopayment.ajax_token,
                available_payment_terms: twopayment.available_payment_terms || [30],
                default_payment_term: twopayment.default_payment_term || 30,
                payment_term_type: twopayment.payment_term_type
            });
            
            // Store global reference for modules
            window.TwoCheckoutManager_Instance = checkoutManager;

            // Sole trader flow (TWO-24755) - separate presentation module.
            // Always constructed; whether the toggle actually renders is
            // decided per billing country by the server-side registry
            // answer via soleTraderAvailability (TWO-25166 removed the
            // merchant opt-in toggle that used to gate this too).
            if (
                typeof TwoSoleTrader !== 'undefined' &&
                !window.TwoSoleTrader_Instance
            ) {
                window.TwoSoleTrader_Instance = new TwoSoleTrader({
                    checkoutHost: twopayment.checkout_host,
                    orderIntentUrl: twopayment.order_intent_url,
                    ajaxToken: twopayment.ajax_token,
                    shopCountry: twopayment.shop_country,
                    i18n: {
                        registered_business: twopayment.i18n && twopayment.i18n.sole_trader_registered_business,
                        sole_trader: twopayment.i18n && twopayment.i18n.sole_trader_label
                    }
                });
            }

        } catch (error) {
            console.error('Two Payment: Initialization failed:', error);
            
            // Fallback: Try again after short delay for theme compatibility
            setTimeout(() => {
                try {
                    if (typeof TwoCheckoutManager !== 'undefined') {
                        if (window.TwoCheckoutManager_Instance && typeof window.TwoCheckoutManager_Instance.cleanup === 'function') {
                            window.TwoCheckoutManager_Instance.cleanup();
                        }

                        const checkoutManager = new TwoCheckoutManager({
                            companySearchEnabled: twopayment.company_name_search === '1',
                            // Keep in step with the primary config object above (TWO-25203).
                            addressLookupEnabled: twopayment.address_lookup !== '0',
                            orderIntentEnabled: true,
                            checkoutHost: twopayment.checkout_host,
                            orderIntentUrl: twopayment.order_intent_url,
                            ajaxToken: twopayment.ajax_token,
                            available_payment_terms: twopayment.available_payment_terms || [30],
                            default_payment_term: twopayment.default_payment_term || 30,
                            payment_term_type: twopayment.payment_term_type
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

    window.addEventListener('beforeunload', function() {
        if (window.TwoCheckoutManager_Instance && typeof window.TwoCheckoutManager_Instance.cleanup === 'function') {
            window.TwoCheckoutManager_Instance.cleanup();
        }
    });
        }); 
    }); 
})(); 
