(function() {
    'use strict';
    let localFallbackAttempted = false;

    // Exposed on `window` for the Jest suite - the module's scripts are plain
    // classic scripts with nothing to require(), so this is how the mapping
    // gets covered at all.
    //
    // The switches arrive in two different shapes and must be read
    // accordingly: the location/lookup switches are Configuration STRINGS
    // ('0'/'1'), the verification verdict is a real boolean. Every one of them
    // is written so that only an explicit "off" value turns anything off - an
    // absent key (an older cached config payload, a theme that rebuilds the
    // payload) must never read as "disabled".
    function buildCheckoutManagerConfig(config) {
        return {
            // TWO-25326 §7.1: decides WHERE the one control renders:
            // '1' (default) = address area, '0' = payment tile. Absent reads
            // as address-area, matching the server-side resolver.
            companySearchInAddressArea: config.company_name_search !== '0',
            // Separate toggle from companySearchInAddressArea (TWO-25203):
            // the address / DNI / VAT fill is independent of where the search
            // widget renders, and only matters when the control is in the
            // address area. Absent reads as enabled, matching the server-side
            // default-on resolver.
            addressLookupEnabled: config.address_lookup !== '0',
            // TWO-25326: an absent key must read as verified rather than take
            // the search away from a working shop.
            apiKeyVerified: config.api_key_verified !== false,
            // TWO-25386 #8: an absent key - an older cached config payload
            // predating this admin toggle - must read as enabled.
            orderIntentEnabled: config.enable_order_intent !== 0
                && config.enable_order_intent !== '0'
                && config.enable_order_intent !== false,
            // TWO-40: seeds the manager's in-memory selection so it survives
            // the page loads the address step is made of - see
            // TwoCheckoutManager.seedConfirmedCompanySelectionFromServer().
            confirmedCompany: config.confirmed_company || null,
            checkoutHost: config.checkout_host,
            orderIntentUrl: config.order_intent_url,
            ajaxToken: config.ajax_token,
            available_payment_terms: config.available_payment_terms || [30],
            default_payment_term: config.default_payment_term || 30,
            payment_term_type: config.payment_term_type
        };
    }

    window.twoBuildCheckoutManagerConfig = buildCheckoutManagerConfig;


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

    function waitForJQuery(callback, maxAttempts = 50) {
        if (typeof jQuery !== 'undefined' && typeof $ !== 'undefined') {
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
            setTimeout(function() {
                waitForJQuery(callback, maxAttempts - 1);
            }, 100);
        } else {
            console.error('Two Payment: jQuery not available after timeout. Module cannot initialize.');
        }
    }
    
    waitForJQuery(function() {
        $(document).ready(function() {
    
    if (typeof twopayment === 'undefined') {
        console.warn('Two Payment: Configuration not found. Module may not be properly loaded.');
        return;
    }
    
    if (typeof TwoCheckoutManager === 'undefined') {
        console.error('Two Payment: TwoCheckoutManager class not found. Check script loading order.');
        return;
    }
    
    function initializeTwoPayment() {
        try {
            if (window.TwoCheckoutManager_Instance && typeof window.TwoCheckoutManager_Instance.cleanup === 'function') {
                window.TwoCheckoutManager_Instance.cleanup();
            }

            const checkoutManager = new TwoCheckoutManager(buildCheckoutManagerConfig(twopayment));
            
            window.TwoCheckoutManager_Instance = checkoutManager;

            // Sole trader flow (TWO-24755). Always constructed; availability is
            // decided per billing country by the server-side registry answer
            // via soleTraderAvailability. The enrolment entry point lives
            // inside TwoCompanySearch's dropdown, which reads this instance's
            // availability cache and calls startEnrollment() directly; this
            // instance owns the enrolment mechanics (token minting, the signup
            // popup, autofill).
            if (
                typeof TwoSoleTrader !== 'undefined' &&
                !window.TwoSoleTrader_Instance
            ) {
                window.TwoSoleTrader_Instance = new TwoSoleTrader({
                    checkoutHost: twopayment.checkout_host,
                    orderIntentUrl: twopayment.order_intent_url,
                    ajaxToken: twopayment.ajax_token,
                    // TWO-25326 bug 9: availability is about the cart's billing
                    // country, not the visitor/shop country. The payment step
                    // renders no country select, so it has to come from the
                    // payload - under its own key, since the search's
                    // `company_search_country` falls back to the shipping address.
                    billingCountry: twopayment.sole_trader_country,
                    shopCountry: twopayment.shop_country,
                    statusLabel: twopayment.i18n && twopayment.i18n.sole_trader_status_label
                });
            }

            // Always constructed: which fields exist in the DOM is
            // decided server-side by the four PS_TWO_ENABLE_* switches, and
            // with none of them on this module finds nothing to mirror.
            if (typeof TwoOptionalFields !== 'undefined') {
                if (
                    window.TwoOptionalFields_Instance &&
                    typeof window.TwoOptionalFields_Instance.cleanup === 'function'
                ) {
                    window.TwoOptionalFields_Instance.cleanup();
                }
                window.TwoOptionalFields_Instance = new TwoOptionalFields({
                    i18n: {
                        invalid_invoice_email: twopayment.i18n && twopayment.i18n.invalid_invoice_email
                    }
                });
            }

        } catch (error) {
            console.error('Two Payment: Initialization failed:', error);
            
            // Retry once for theme compatibility.
            setTimeout(() => {
                try {
                    if (typeof TwoCheckoutManager !== 'undefined') {
                        if (window.TwoCheckoutManager_Instance && typeof window.TwoCheckoutManager_Instance.cleanup === 'function') {
                            window.TwoCheckoutManager_Instance.cleanup();
                        }

                        const checkoutManager = new TwoCheckoutManager(buildCheckoutManagerConfig(twopayment));
                        window.TwoCheckoutManager_Instance = checkoutManager;
                    }
                } catch (retryError) {
                    console.error('Two Payment: Retry initialization also failed:', retryError);
                }
            }, 1000);
        }
    }
    
    initializeTwoPayment();

    window.addEventListener('beforeunload', function() {
        if (window.TwoCheckoutManager_Instance && typeof window.TwoCheckoutManager_Instance.cleanup === 'function') {
            window.TwoCheckoutManager_Instance.cleanup();
        }
        if (window.TwoOptionalFields_Instance && typeof window.TwoOptionalFields_Instance.cleanup === 'function') {
            window.TwoOptionalFields_Instance.cleanup();
        }
    });
        }); 
    }); 
})(); 
