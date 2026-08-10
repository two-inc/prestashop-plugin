/**
 * Two Payment Module - Production Ready with Enhanced Compatibility
 * Defensive initialization for merchant environment compatibility
 */

// CRITICAL FIX: Ensure jQuery is available before executing
(function() {
    'use strict';
    let localFallbackAttempted = false;

    // ONE translation of the server config payload into TwoCheckoutManager's
    // config, used by both construction sites below (TWO-25326 review round 2:
    // they were duplicated, so the retry copy could silently drift from the
    // primary one, and neither was reachable from a test). Exposed on `window`
    // for the Jest suite - the module's scripts are plain classic scripts with
    // nothing to require(), so this is how the mapping gets covered at all.
    //
    // The switches arrive in two different shapes on purpose and must be read
    // accordingly: the location/lookup switches are Configuration STRINGS
    // ('0'/'1'), the verification verdict is a real boolean. Every one of them
    // is written so that only an explicit "off" value turns anything off - an
    // absent key (an older cached config payload, a theme that rebuilds the
    // payload) must never read as "disabled".
    function buildCheckoutManagerConfig(config) {
        return {
            // TWO-25326 §7.1 (2026-08-03 ruling): this switch used to be
            // on/off for the search widget's existence. It now decides WHERE
            // the one control renders: '1' (default) = address area, unchanged
            // from before this ticket; '0' = the same control relocates into
            // the payment tile instead. Absent reads as address-area, matching
            // the server-side resolver.
            companySearchInAddressArea: config.company_search_in_address_area !== '0',
            // Separate toggle from companySearchInAddressArea (TWO-25203):
            // the address / DNI / VAT fill can be on or off independent of
            // where the search widget itself renders, and only matters at
            // all when the control is in the address area. Absent reads
            // as enabled, matching the server-side default-on resolver.
            addressLookupEnabled: config.address_lookup !== '0',
            // TWO-25326: only an explicit false disables the company search.
            // The server sends a real boolean; an absent key must read as
            // verified rather than take the search away from a working shop.
            apiKeyVerified: config.api_key_verified !== false,
            // TWO-25386 #8: only an explicit off (0, '0' or false) disables
            // the order-intent pre-approval preview. An absent key - an older
            // cached config payload predating this admin toggle - must read
            // as enabled, matching the previously-hardcoded-true behaviour.
            orderIntentEnabled: config.enable_order_intent !== 0
                && config.enable_order_intent !== '0'
                && config.enable_order_intent !== false,
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
            const checkoutManager = new TwoCheckoutManager(buildCheckoutManagerConfig(twopayment));
            
            // Store global reference for modules
            window.TwoCheckoutManager_Instance = checkoutManager;

            // Sole trader flow (TWO-24755) - separate presentation module.
            // Always constructed; availability is still decided per billing
            // country by the server-side registry answer via
            // soleTraderAvailability (TWO-25166 removed the merchant opt-in
            // toggle that used to gate this too). TWO-40 removed the
            // Business / Sole trader chip UI this module used to render - the
            // enrolment entry point now lives inside TwoCompanySearch's
            // dropdown, which reads this instance's availability cache and
            // calls startEnrollment() directly. This instance still owns all
            // enrolment mechanics (token minting, the signup popup, autofill).
            if (
                typeof TwoSoleTrader !== 'undefined' &&
                !window.TwoSoleTrader_Instance
            ) {
                window.TwoSoleTrader_Instance = new TwoSoleTrader({
                    checkoutHost: twopayment.checkout_host,
                    orderIntentUrl: twopayment.order_intent_url,
                    ajaxToken: twopayment.ajax_token,
                    // TWO-25326 bug 9, round 3: the cart's billing country, which
                    // is what availability is actually about. The payment step
                    // renders no country select, so without this the module fell
                    // back to `shop_country` - the visitor/shop country, not the
                    // country the order will be billed to - and re-resolved
                    // availability for the wrong one. Already in the payload for
                    // the company search (TwoCompanySearch reads the same key);
                    // this just stops a second consumer guessing.
                    billingCountry: twopayment.billing_country,
                    shopCountry: twopayment.shop_country,
                    // Fallback status label only - the chip labels this used
                    // to carry are gone along with the chips (TWO-40).
                    statusLabel: twopayment.i18n && twopayment.i18n.sole_trader_status_label
                });
            }

            // Optional buyer reference fields (ABN-472) - mirrors the visible
            // tile inputs into the payment form's hidden twins. Always
            // constructed: which fields exist in the DOM is decided
            // server-side by the four PS_TWO_ENABLE_* switches, and with none
            // of them on this module simply finds nothing to mirror.
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

            // TwoCompanySummary (read-only tile label, TWO-25288) REMOVED by
            // TWO-25326 §7.3 (2026-08-03 ruling) - the captured company now
            // lives only inside the intent-message sentence.

        } catch (error) {
            console.error('Two Payment: Initialization failed:', error);
            
            // Fallback: Try again after short delay for theme compatibility
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
    
    // Initialize immediately
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
