/**
 * Two Checkout Manager - IMPROVED with PrestaShop-native patterns
 * Theme-independent, using native PrestaShop events and patterns
 */
class TwoCheckoutManager {
    constructor(config) {
        this.config = {
            // Default-on, mirroring the server-side resolver: the address
            // lookup was unconditional before the toggle existed (TWO-25203),
            // so an omitted value must not turn it off.
            addressLookupEnabled: true,
            // TWO-25326 §7.1 (2026-08-03 ruling): the EXISTING
            // PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS switch now decides WHERE the ONE
            // company-search control renders, not whether it exists - the
            // control is never off. true (default): address area, unchanged
            // from before this ticket. false: the same control relocates
            // into the payment tile instead.
            companySearchInAddressArea: true,
            // TWO-25326: whether the shop's stored API key currently verifies.
            // Default-on, matching every other switch here: an omitted value
            // must never be read as "the integration is broken" and take the
            // company search away from a shop that is fine. The server sends a
            // real boolean; only an explicit false disables anything.
            apiKeyVerified: true,
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
        // TWO-25326: in tile mode, has the buyer actually picked (or
        // manually entered) a company yet? Set true by
        // TwoCompanySearch.onCompanySelected() the moment a search result is
        // picked - see canAutoTriggerOrderIntent(). Irrelevant in address
        // mode, where company capture already happened at the address step,
        // well before the payment tile (and this flag) exist.
        this._tileCompanySelected = false;
        // TWO-25326 bug 8: the company the buyer has actually picked, held for
        // the page's lifetime rather than on the search instance that a
        // re-render replaces. See getConfirmedCompanySelection().
        this._confirmedCompanySelection = null;
        // TWO-40: what the invoice-address mirror has already written on this page.
        // Lives HERE, not on the search instance, because this class destroys and
        // rebuilds that instance on every `updatedAddressForm` - and the mirror's
        // two rules both need to outlive the rebuild: do not populate a second time
        // (a cleared field must stay cleared), and re-mark a value core's own form
        // rebuild stripped the marker off. See
        // TwoCompanySearch.mirrorConfirmedCompanyToInvoiceAddress().
        this._invoiceMirrorMemory = {};
        // TWO-40: and where the server has one for this cart, start from it. Must
        // run before init(), which is what constructs the modules that read the
        // selection back out.
        this.seedConfirmedCompanySelectionFromServer();
        // Monotonic sequence for surcharge cart-line syncs: only the LATEST
        // selection's response may drive the UI (last-wins against re-ordered
        // AJAX responses when the buyer clicks between options quickly), and
        // the same value is sent to the server so a slower OLDER request can
        // never overwrite a newer one there either. Seeded with Date.now()
        // (not 0) so the sequence stays monotonic across page reloads - the
        // server persists the last-applied value per cart.
        this._surchargeSyncSeq = Date.now();
        // Monotonic sequence for the in-place order-summary refreshes that follow
        // a sync which actually changed the cart - see refreshCartSummaryInPlace().
        this._summaryRefreshSeq = 0;

        this.init();
    }

    t(key, fallback) {
        if (window.twopayment && window.twopayment.i18n && window.twopayment.i18n[key]) {
            return window.twopayment.i18n[key];
        }
        return fallback;
    }

    /**
     * Is the order-intent APPROVED notice enabled for this brand? (TWO-25218)
     * Mirror of TwoOrderIntent.approvedNoticeEnabled(): only an explicit `false`
     * turns it off, and an absent or non-boolean value reads as ENABLED so an
     * older cached JS file or template can never mean off.
     *
     * Read locally rather than through this.orderIntent so it is correct before
     * that module is initialised.
     */
    approvedNoticeEnabled() {
        const configured = window.twopayment ? window.twopayment.intent_approved_notice_enabled : null;
        return typeof configured === 'boolean' ? configured : true;
    }

    /**
     * Copy override for that notice (TWO-25218). Mirror of
     * TwoOrderIntent.approvedNoticeOverride() - null = platform default copy,
     * non-empty = verbatim company-variant template. Empty and whitespace-only
     * are inert (default copy); this key does not switch the notice off.
     */
    approvedNoticeOverride() {
        const configured = window.twopayment ? window.twopayment.intent_approved_notice : null;
        if (typeof configured !== 'string' || configured.trim() === '') {
            return null;
        }
        return configured;
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

        // The company-search control mounted (initializeModules(), above) while
        // its tile may still have been collapsed by core, so in tile mode it
        // measured a field inside a hidden container. Re-measure at the end of
        // init, so the control is laid out from a real measurement on this load
        // rather than on the next viewport change.
        this.remeasureCompanySearchLayout();

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
            if (this.isTwoPaymentSelected() && this.config.orderIntentEnabled && this.canAutoTriggerOrderIntent()) {
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

        if (isTwoSelected && this.config.orderIntentEnabled && this.canAutoTriggerOrderIntent()) {
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
                    this.refreshCartSummaryInPlace();
                } else if (!response || !response.success) {
                    console.warn('Two Payment: surcharge cart line sync failed; server-side order-create gate remains authoritative.');
                }
                return response;
            });
    }

    /**
     * Bring the checkout's order summary in line with the surcharge cart line
     * WITHOUT navigating the document (TWO-25326 bug 11, round 4).
     *
     * WHAT THIS REPLACES, and why the previous approach could not be patched
     * into working. The module used to emit core's own `updateCart` event, which
     * is the documented way to ask PrestaShop to re-render the cart - but on the
     * payment step core deliberately turns that into a full page NAVIGATION:
     * `themes/_core/js/cart.js` calls `refreshCheckoutPage()`
     * (`themes/_core/js/common.js`), which sets
     * `window.location.href = pathname + '?updatedTransaction=1'` and, once that
     * parameter is already on the URL, `window.location.reload()`. Core's own
     * comment gives the reason: "on payment step we need to refresh page to be
     * sure amount is correctly updated on payment modules".
     *
     * That navigation IS the flicker, in both of its reported shapes:
     *  - SELECTING Two: the tile the buyer has just opened is destroyed with the
     *    old document and rebuilt in the new one - "rendered, then removed and
     *    re-rendered". There is no first paint to suppress here; the tile
     *    genuinely goes away and comes back.
     *  - SELECTING SOMETHING ELSE: the tile collapses (correct), and then the
     *    reloaded document paints every payment option's additional-information
     *    block VISIBLE, because core hides the unselected ones only once
     *    `Payment.init()` -> `toggleOrderButton()` -> `collapseOptions()` runs at
     *    DOM ready (`themes/_core/js/checkout-payment.js`). So the tile returns
     *    for a moment and goes again.
     *
     * Round 3 kept the navigation and tried to hide its artefacts at first paint
     * instead. That could only ever address the second shape - it deliberately
     * did nothing when Two was the option being restored - so the first shape was
     * never addressed at all. The navigation itself is what had to go.
     *
     * WHAT REPLACES IT: exactly what core's own `updateCart` handler does, MINUS
     * the navigation. POST the same `.js-cart` refresh URL and swap the same
     * summary partials out of the response. The buyer-visible totals update, the
     * document is never navigated, and there is no artefact left to hide.
     *
     * WHAT THE NAVIGATION BOUGHT, AND WHY GIVING IT UP IS SAFE: re-rendering the
     * payment options so OTHER payment modules recompute against the new amount.
     * Nothing here needs that. The fee line exists only while Two is the selected
     * option and is removed the instant another one is picked, and the module
     * additionally strips it server-side before any other payment module's
     * controller can compute totals (the `actionFrontControllerInitAfter`
     * stale-guard). The authoritative check is, as before, the order-create
     * parity gate, which fails closed.
     *
     * FAIL-SOFT, like the sync it follows: a theme with no `.js-cart` refresh URL,
     * or a failed request, leaves the summary showing its previous total. That is
     * a display staleness, never a charge: a buyer cannot complete a Two order
     * whose PrestaShop total diverges from the Two invoice.
     *
     * @returns {void}
     */
    refreshCartSummaryInPlace() {
        if (typeof jQuery === 'undefined') {
            return;
        }
        const refreshUrl = jQuery('.js-cart').data('refresh-url');
        if (!refreshUrl) {
            // Not an error state worth alarming the buyer about, but it does mean
            // the summary will lag until the next page load, so it is logged.
            console.warn('Two Payment: no cart refresh URL in this theme; the order summary keeps its previous total until the next page load.');
            return;
        }

        // Last-wins, for the same reason the sync itself is sequenced: a buyer
        // clicking between options fires several of these, and the summary each
        // response carries is the cart AS OF that request. Without this, a slow
        // earlier response landing after a newer one repaints the totals with the
        // older cart - the sync's own guard cannot cover it, because by the time
        // it runs each of those syncs WAS the newest.
        const refreshSeq = ++this._summaryRefreshSeq;
        jQuery.post(refreshUrl, {})
            .done((response) => {
                if (refreshSeq !== this._summaryRefreshSeq) {
                    return;
                }
                this.applyCartSummaryPartials(response);
            })
            .fail(() => {
                console.warn('Two Payment: cart summary refresh failed; the server-side order-create gate remains authoritative.');
            });
    }

    /**
     * Swap in the summary partials the cart-refresh endpoint returns.
     *
     * The response keys and the elements they replace are core's, taken from its
     * own `updateCart` handler; `prestashop.selectors.cart` is read when core has
     * published it and the literal selector is used otherwise, because that map is
     * supplied by core's `selectors.js` and a theme that has not loaded it would
     * otherwise silently update nothing.
     *
     * Two things core's handler also does are deliberately NOT done here:
     * resetting `#product_customization_id` and re-stamping the cart lines'
     * quantity inputs. Both belong to editing a cart LINE from the cart page;
     * this call only ever follows a payment-fee sync, where no product line the
     * buyer can edit has changed.
     *
     * A missing or non-string value is skipped rather than written, so a partial
     * response cannot blank a section of the summary.
     *
     * @param {Object} response the endpoint's JSON
     * @returns {void}
     */
    applyCartSummaryPartials(response) {
        if (!response || typeof response !== 'object') {
            return;
        }
        const coreSelectors = (window.prestashop && window.prestashop.selectors && window.prestashop.selectors.cart)
            ? window.prestashop.selectors.cart
            : null;
        const partials = [
            ['cart_detailed_totals', 'detailedTotals', '.cart-detailed-totals, .js-cart-detailed-totals'],
            ['cart_summary_items_subtotal', 'summaryItemsSubtotal', '.cart-summary-items-subtotal, .js-cart-summary-items-subtotal'],
            ['cart_summary_subtotals_container', 'summarySubTotalsContainer', '.cart-summary-subtotals-container, .js-cart-summary-subtotals-container'],
            ['cart_summary_products', 'summaryProducts', '.cart-summary-products, .js-cart-summary-products'],
            ['cart_summary_totals', 'summaryTotals', '.cart-summary-totals, .js-cart-summary-totals'],
            ['cart_detailed_actions', 'detailedActions', '.cart-detailed-actions, .js-cart-detailed-actions'],
            ['cart_voucher', 'voucher', '.cart-voucher, .js-cart-voucher'],
            ['cart_detailed', 'overview', '.cart-overview'],
            ['cart_summary_top', 'summaryTop', '.cart-summary-top, .js-cart-summary-top']
        ];

        partials.forEach((partial) => {
            const html = response[partial[0]];
            if (typeof html !== 'string' || html === '') {
                return;
            }
            const selector = (coreSelectors && coreSelectors[partial[1]]) || partial[2];
            const target = jQuery(selector);
            if (!target.length) {
                return;
            }
            // FIRST match only, deliberately (review round 1). Every one of these
            // selectors is a two-convention alternation - `.cart-summary-totals,
            // .js-cart-summary-totals` - and core's own handler calls
            // `replaceWith()` on the whole matched set. On the shipped classic
            // theme that is one element either way, because both classes sit on
            // the SAME node (verified on the delivered checkout markup:
            // `class="card-block cart-summary-totals js-cart-summary-totals"`).
            // But a theme that spelled the two conventions as two SEPARATE nodes
            // would get the replacement HTML written into each of them - a
            // duplicated totals block, which is visible corruption, and worse
            // than the alternative failure of leaving a second stale copy alone.
            // So: replace one, and say so when there was more than one rather
            // than silently picking.
            if (target.length > 1) {
                console.warn('Two Payment: ' + selector + ' matched ' + target.length + ' elements; refreshing the first only.');
            }
            target.first().replaceWith(html);
        });
    }

    /**
     * Re-measure the company-search control's layout (TWO-25326).
     *
     * In tile mode the control mounts inside a tile core may still have
     * collapsed, so it measured a field with no measurable width. That case is
     * handled where it is measured - no measurable width leaves the wrapper's
     * pixel pin CLEARED rather than pinned to zero - but the wrapper then keeps
     * the stylesheet's width until the next viewport change. This re-measures at
     * the end of init instead.
     *
     * Presentation only, and never a gate on checkout: anything thrown here is
     * swallowed.
     *
     * @returns {void}
     */
    remeasureCompanySearchLayout() {
        try {
            if (this.companySearch && typeof this.companySearch.ensureFieldWrapper === 'function') {
                this.companySearch.ensureFieldWrapper();
                if (typeof this.companySearch.constrainAutocompleteMenuWidth === 'function') {
                    this.companySearch.constrainAutocompleteMenuWidth();
                }
            }
        } catch (e) {
            // Presentation only; never a gate on checkout.
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

        if (isTwoSelected && this.config.orderIntentEnabled && this.canAutoTriggerOrderIntent()) {
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
     * TWO-25326: is it safe to auto-fire the order-intent check off a generic
     * "Two payment selected/mounted/re-rendered" signal, as opposed to an
     * actual company selection?
     *
     * In address mode this is always true: by the time the payment step (and
     * this generic signal) exists, address-step company capture - or its
     * deliberate absence - is already resolved, well before the payment tile
     * even renders.
     *
     * In tile mode the company control lives INSIDE the tile, so the same
     * generic signal fires the instant the tile mounts or Two is
     * auto-selected as the only payment method - before the buyer has picked
     * anything. Gate it on an actual selection (or manual entry - "My
     * company is not on the list" is a choice too, just not one made through
     * search results) having happened first.
     *
     * Deliberately NOT applied to triggerOrderIntentForSelection() callers
     * that ARE the selection event itself (TwoCompanySearch's own
     * onCompanySelected callback) or the payment-confirmation submit-time
     * check (handlePaymentConfirmation) - the latter is the last-resort
     * safety net that must still run and block submission if the buyer never
     * selected anything.
     *
     * @returns {boolean}
     */
    canAutoTriggerOrderIntent() {
        if (this.config.companySearchInAddressArea) {
            return true;
        }
        if (this._tileCompanySelected) {
            return true;
        }
        return !!(this.companySearch
            && typeof this.companySearch.isManualEntry === 'function'
            && this.companySearch.isManualEntry());
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
                if (this.suppressCompanyRelocationPrompt()) {
                    return;
                }
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

        // Build company-aware message for display (translated). Sentence
        // built by TwoOrderIntent.buildCompanyIntentMessage() (TWO-25326
        // §7.3) - the single place that templates name/number into the
        // wording, shared with this module's own updateUI()/processResult().
        const selectedCompany = this.getSelectedCompany();
        const companyName = selectedCompany.name;
        const companyNumber = selectedCompany.number;
        if (result.approved) {
            let approvedMsg = result.message || this.t('payment_approved_message', 'Payment approved! Choose your payment terms below.');
            if (companyName && this.orderIntent && typeof this.orderIntent.buildCompanyIntentMessage === 'function') {
                approvedMsg = this.orderIntent.buildCompanyIntentMessage(true, companyName, companyNumber);
            }
            this.showOrderIntentApproval(approvedMsg);
        } else {
            // For declined results, also check if the decline reason should be treated as an error
            const baseDecline = result.message || this.t('payment_not_available_message', 'Two payment is not available for this order.');
            let declineMessage = baseDecline;
            if (companyName && this.orderIntent && typeof this.orderIntent.buildCompanyIntentMessage === 'function') {
                declineMessage = this.orderIntent.buildCompanyIntentMessage(false, companyName, companyNumber);
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
     * Get the selected company name+number as ONE atomic pair, for the
     * customer-visible intent sentence (TWO-25326 §7.3).
     *
     * Adversarial review round 3: this used to be two separate methods
     * (getSelectedCompanyName/getSelectedCompanyNumber), each independently
     * falling back to a DOM field when its own `this.orderIntent` value was
     * falsy. That defeated TwoOrderIntent's own joint-reassignment
     * guarantee (round 2's fix) from one layer up - a falsy
     * `lastCompanyNumber` (a genuine, valid "no number" case, e.g. manual
     * entry) would fall through to whatever `input[name='companyid']`
     * happened to still hold from an EARLIER, unrelated company selection,
     * silently re-pairing it with the current name.
     *
     * `this.orderIntent` is the authoritative, already-paired source
     * whenever it has ANY answer at all (including "name with no number" -
     * checked via `hasOwnProperty`-style truthiness on `lastCompany` alone,
     * never gated on `lastCompanyNumber` too). The DOM fallback below is
     * only for the case orderIntent hasn't run yet at all, and reads both
     * fields together, from the same location, in one pass - never one
     * from orderIntent and the other from the DOM.
     *
     * @returns {{name: string, number: string}}
     */
    getSelectedCompany() {
        try {
            if (this.orderIntent && this.orderIntent.lastCompany) {
                return {
                    name: this.orderIntent.lastCompany,
                    number: this.orderIntent.lastCompanyNumber || ''
                };
            }
            // TWO-25326 §7.1: the address-area fields are only a
            // trustworthy fallback when the search control actually lives
            // there - in tile mode `company` stays visible/typeable by
            // design (never hidden) but is not where the buyer's real
            // selection came from, and `companyid` is the TILE's own hidden
            // field, unrelated to whatever the address field currently holds.
            if (this.config.companySearchInAddressArea !== false) {
                const companyField = document.querySelector("input[name='company']");
                const companyIdField = document.querySelector("input[name='companyid']");
                const name = companyField && companyField.value ? companyField.value.trim() : '';
                const number = companyIdField && companyIdField.value ? companyIdField.value.trim() : '';
                if (name) {
                    return { name: name, number: number };
                }
            }
        } catch (e) {}
        return { name: '', number: '' };
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
     * Swallow the "go back to your billing address and search for your company
     * name" family of prompts when the company-search control is mounted in
     * the payment tile (TWO-25326 §7.1 follow-up).
     *
     * Every one of those prompts - and the "Buy now, pay later - instant
     * credit" subtitle they render underneath, which is the same
     * `.two-payment-info` block - tells the buyer to go somewhere else and do
     * something there. That is correct while the search lives in the address
     * area, and simply wrong once the switch has moved it into the tile: the
     * control the buyer is being sent away to find is the one they are looking
     * at. There is nothing to reword it to, because the instruction itself is
     * the thing that no longer applies, so the block is not rendered at all.
     *
     * Deliberately NOT routed through getOrCreateMessageContainer(): that
     * method's side effect is to reveal `.two-payment-info`, which is exactly
     * what is being suppressed. Only an ALREADY-rendered container is touched,
     * and only to clear a prompt an earlier check left on screen.
     *
     * @returns {boolean} true when the prompt was suppressed and the caller
     *          must render nothing
     */
    suppressCompanyRelocationPrompt() {
        if (this.config.companySearchInAddressArea !== false) {
            return false;
        }
        const container = document.querySelector('.two-payment-info')
            || document.querySelector('#two-order-intent-messages');
        if (container) {
            const messageElement = container.querySelector('.two-payment-message');
            if (messageElement) {
                messageElement.textContent = '';
            }
            container.classList.remove('show', 'approved', 'loading', 'declined', 'action-required');
            container.style.display = 'none';
        }
        this.clearLoadingState();
        this.hideLoadingOverlay();
        return true;
    }

    /**
     * Show company required message with clear guidance (theme-independent)
     * @param {string} message - The error message from backend
     * @param {string} status - The status code: 'no_company' or 'incomplete_company'
     */
    showCompanyRequiredMessage(message, status) {
        if (this.suppressCompanyRelocationPrompt()) {
            return;
        }
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
     *
     * Renders nothing when the brand suppressed the approved notice
     * (TWO-25224). That switch turns off the buyer-facing *reassurance
     * messaging* around the order-intent pre-check, and this overlay is part
     * of it - it carries our own "Checking Two payment eligibility..." copy,
     * so a brand that declined the approval sentence was still announcing the
     * check. Errors (showOrderIntentError / showOrderIntentDecline) are NOT
     * gated on the switch: a merchant who wants no reassurance still needs
     * failures surfaced, or a declined buyer sees nothing at all.
     *
     * The isLoadingUIShown bookkeeping below still happens either way - it is
     * the in-flight guard that stops the periodic selection check and the
     * step-change handler from firing a second intent, not a fact about the
     * DOM. Skipping it would double-fire the intent for suppressed brands.
     */
    showOrderIntentLoading() {
        if (!this.twoPaymentOption) return;
        if (!this.approvedNoticeEnabled()) {
            this.isLoadingUIShown = true;
            return;
        }
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
        // Notice switched off for this brand (TWO-25218): render no approval
        // message and do not create a container for one. Everything else an
        // approval does - clearing the loading state, revealing the payment
        // terms selector - still has to happen.
        if (!this.approvedNoticeEnabled()) {
            const existing = document.querySelector('.two-payment-info') ||
                document.querySelector('#two-order-intent-messages');
            if (existing) {
                const existingMessage = existing.querySelector('.two-payment-message');
                if (existingMessage) {
                    existingMessage.textContent = '';
                }
                existing.classList.remove('approved', 'declined', 'loading', 'show');
                existing.style.display = 'none';
            }
            this.clearLoadingState();
            this.hideLoadingOverlay();
            this.showPaymentTerms();
            return;
        }

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
            // The only thing this branch has to say is "go and search for your
            // company" - which is the tile's own search control's job to
            // prompt for once that control lives here, so there is nothing
            // left to render. Checked before the generic branch on purpose: a
            // real error with company data present still surfaces below.
            if (this.suppressCompanyRelocationPrompt()) {
                return;
            }
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
     *
     * TWO-40: TWO carriers, not one. The hidden `companyid` input is the DOM's
     * copy; the page-lifetime holder is the other, and it can legitimately hold a
     * real selection while the DOM field is empty or absent altogether - on the
     * payment step, where PrestaShop has removed the address form, the selection
     * comes from seedConfirmedCompanySelectionFromServer() adopting the server's
     * cart-scoped record. Reading only the DOM there misreports a genuine
     * order-intent failure (a 500, a timeout) as "you didn't pick a company", and
     * in tile mode showOrderIntentError() then swallows that branch entirely
     * (suppressCompanyRelocationPrompt() returns early) - so the buyer is shown
     * nothing at all for a real failure.
     *
     * @returns {boolean} True if company org number is missing
     */
    isCompanyDataMissing() {
        // The hidden `companyid` field is the FIRST of the two carriers named in
        // this method's docblock, and it is consulted before the page-lifetime
        // selection below: while the address form is on screen the DOM field is the
        // live one, and the seeded selection stands in for it only once the field is
        // empty or gone. There used to be a `document.cookie` fallback here
        // looking for a bare `two_company_id`; nothing ever wrote one. PrestaShop
        // serialises every server-side session key into a single encrypted cookie
        // under its own name, so a server write of that key never produces a
        // browser cookie called `two_company_id`, and no code in this module sets
        // one directly. The fallback could only ever be satisfied by a test that
        // fabricated the cookie itself, so it has been removed rather than left
        // looking like a real second source.
        let orgNumber = '';

        const companyIdField = document.querySelector("input[name='companyid']");
        if (companyIdField && companyIdField.value) {
            orgNumber = companyIdField.value.trim();
        }

        if (!orgNumber) {
            // The second carrier - see this method's docblock. Through the same
            // getter every other consumer of the selection reads, so the two
            // cannot answer differently.
            const confirmed = this.getConfirmedCompanySelection();
            if (confirmed && confirmed.companyid) {
                orgNumber = String(confirmed.companyid).trim();
            }
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

        // TWO-25326 §7.1: unconditionally, not just on the edge above - a
        // payment-fragment REPLACEMENT (twoPaymentOption non-null both
        // before and after) never satisfies the edge check, but can still
        // swap out the mounted #two_tile_company node. initializeCompanySearch()
        // is cheap to call repeatedly: it no-ops unless the previously
        // mounted field has actually been detached (see its own comment).
        if (!this.config.companySearchInAddressArea) {
            this.initializeCompanySearch();
        }
        
        // Deliberately NOT re-running setupPaymentOptionSelectionListener()
        // here (TWO-25326 render-loop bug: it used to force
        // `_paymentListenersAttached = false` and call it again on every
        // debounced MutationObserver firing - i.e. every time PrestaShop
        // replaced the `.payment-options` fragment while the checkout step
        // settled). Every listener that method binds is delegated to
        // `document`, not to any node inside that fragment, so it keeps
        // matching the tile's radio/click/submit events across any number
        // of DOM replacements without ever being re-attached. Re-running it
        // anyway added a fresh, permanent set of duplicate document-level
        // listeners (plus another Method-5 setInterval, never cleared) on
        // every firing - so a single click could invoke
        // handlePaymentOptionChange() several times concurrently, each
        // independently racing syncSurchargeCartLine() -> back when that
        // navigated the payment step, a stack of full page reloads, which
        // is what Doug saw as the tile rendering and being removed several
        // times in a row. That navigation is gone now
        // (refreshCartSummaryInPlace), but the duplicate listeners are
        // still worth never creating.
        // cleanup() has no matching removeEventListener calls (the handlers
        // are anonymous closures, never kept), so nothing this module does
        // can safely undo the duplicates once bound - the fix is to never
        // create them, not to fight the guard that already existed to
        // prevent exactly this.

        // Initialize modules if needed
        if (this.isBusinessAccount && this.config.orderIntentEnabled && !this.orderIntent) {
            this.initializeOrderIntent();
        }
        
        // If on payment step and Two is selected, trigger order intent
        if (this.currentStep === 'payment' && this.isTwoPaymentSelected() && this.config.orderIntentEnabled && this.canAutoTriggerOrderIntent()) {
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

        // Re-initialize company search when address form updates. Tile mode
        // (TWO-25326 §7.1) does nothing here deliberately: the address
        // area's native `company` field stays a plain, unenhanced, typeable
        // text input in that mode (never hidden, never removed - confirmed
        // bug on woocommerce-plugin, checked not to recur here) and is never
        // the search's mount point, so an address-form re-render has nothing
        // for this module to redo.
        if (this.config.companySearchInAddressArea) {
            if (this.companySearch && this.companySearch.destroy) {
                this.companySearch.destroy();
                this.companySearch = null;
            }
            this.initializeCompanySearch();
        }

        // TWO-25326 bug 8, review round 1: the buyer is in the address form, so
        // the in-memory confirmed selection stops being trustworthy and must
        // stop out-ranking the session cookie.
        //
        // Both guards on that selection (address id, country ISO) compare a
        // value captured when it was made against one resolved when it is used
        // - and in tile mode BOTH are unresolvable at the payment step, because
        // PrestaShop has removed the address form. So a buyer who selects a
        // company in the tile, goes back, changes the address country and
        // returns has changed nothing either guard can see. In tile mode the
        // module also does not rebuild the search instance here, so
        // TwoCompanySearch's own country-change listener - which clears the
        // selection - is never bound to the re-rendered select either.
        //
        // Gated on a country select actually being in the DOM, deliberately:
        // PrestaShop emits `updatedAddressForm` for ordinary things and it can
        // land tens of milliseconds after an unrelated click (see
        // TwoCompanySearch's own note on this event), so an unconditional clear
        // here could wipe a selection the buyer had just made and put bug 8
        // straight back. A country select being present means an address form is
        // genuinely rendered, which is the only case this needs to cover.
        if (document.querySelector("select[name='id_country'], select[name='country']")) {
            this.clearConfirmedCompanySelection();
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
            if (this.isTwoPaymentSelected() && this.orderIntent && this.canAutoTriggerOrderIntent()) {
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

        // TWO-25326 §7.1: company search is never off - only WHERE it
        // renders varies (this.config.companySearchInAddressArea). In tile
        // mode the address-area's native `company` field is left exactly
        // alone - plain, unenhanced, still typeable - never hidden or
        // removed (confirmed bug on woocommerce-plugin, checked not to
        // recur here).
        if (this.config.companySearchInAddressArea) {
            if (this.currentStep === 'address') {
                this.initializeCompanySearch();
            }
        } else {
            // Try to mount on the tile field every time this runs (idempotent
            // - initializeCompanySearch() no-ops once this.companySearch is
            // set, and again if the tile field isn't in the DOM yet, since
            // PrestaShop loads payment options via a later AJAX call). The
            // call that actually succeeds is the one after
            // handleDynamicContentChange() detects the payment option first
            // appearing (same re-init edge the rest of this module already
            // relies on for AJAX-loaded payment options).
            this.initializeCompanySearch();
        }

        // Phone validation removed - Two API handles validation

        // Initialize order intent for payment step with business accounts
        if (this.config.orderIntentEnabled && this.currentStep === 'payment' && this.isBusinessAccount) {
            this.initializeOrderIntent();
            // If Two is already selected by default (only payment method), trigger intent once.
            // TWO-25326: gated on canAutoTriggerOrderIntent() - in tile mode this method runs
            // on the tile's initial mount, before the buyer has picked a company from the
            // search results it contains, so firing unconditionally here was the bug.
            if (this.isTwoPaymentSelected() && !this._initialIntentTriggered && this.canAutoTriggerOrderIntent()) {
                this._initialIntentTriggered = true;
                this.triggerOrderIntentForSelection();
            }
        }
    }
    
    /**
     * Initialize company search module
     *
     * TWO-25326 §7.1 (2026-08-03 ruling): the admin setting decides WHERE the
     * one shared control mounts, never whether it exists. When the tile mount
     * point (`#two_tile_company`, rendered by paymentinfo.tpl only when the
     * setting is on) is present, TwoCompanySearch attaches to THAT field
     * instead of the address form's `input[name='company']` - same class,
     * same dropdown/query-field/manual-entry behaviour, never a second
     * implementation. The address-area native field is deliberately left
     * ALONE in this mode - never hidden, never disabled, still a plain
     * typeable text input (a real regression on woocommerce-plugin;
     * checked not to recur here, see the e2e coverage for this file).
     */
    initializeCompanySearch() {
        // API-key verification gate (TWO-25326). Two is not offered at this
        // checkout when the shop's key does not verify (the payment option is
        // withheld server-side on the same condition), so there is nothing for a
        // selected company to be used FOR - and a "search and verify your
        // company" journey that leads nowhere is worse than a plain field.
        //
        // Not because the search would break: that endpoint is called
        // unauthenticated (see buildPublicApiBeforeSend in TwoCompanySearch) and
        // works regardless of the key. Leave the company field as the plain text
        // input the theme rendered, and strip the search-mode placeholder the
        // address-form override put on it, so it does not tell the buyer to
        // search a field that no longer does.
        if (this.config.apiKeyVerified === false) {
            this.neutralizeCompanySearchAffordance();
            return;
        }
        // TWO-25326 §7.1: in tile mode, PrestaShop can replace the whole
        // payment-options fragment (a surcharge/cart-line sync, a payment-
        // form refresh) with a FRESH #two_tile_company node. The re-init
        // trigger elsewhere in this module (handleDynamicContentChange) only
        // reacts to `this.twoPaymentOption` going from absent to present, a
        // one-shot edge - a same-instant swap of an already-present option
        // never fires it. Detect a stale mount directly instead: if the
        // field this.companySearch actually attached to is no longer in the
        // document, drop the instance so the code below creates a fresh one
        // against whatever #two_tile_company exists now.
        if (this.companySearch && !this.config.companySearchInAddressArea) {
            const mountedField = this.companySearch.companyField && this.companySearch.companyField.get
                ? this.companySearch.companyField.get(0)
                : null;
            if (!mountedField || !mountedField.isConnected) {
                if (typeof this.companySearch.destroy === 'function') {
                    try {
                        this.companySearch.destroy();
                    } catch (e) { /* noop */ }
                }
                this.companySearch = null;
            }
        }
        if (this.companySearch || !window.TwoCompanySearch) {
            return;
        }
        if (!this.config.companySearchInAddressArea) {
            // The tile mount point renders later than the address step
            // (PrestaShop loads payment options via AJAX) - if it is not in
            // the DOM yet, do nothing rather than falling back to the
            // address-area field. initializeModules() calls this again on
            // every subsequent re-init edge (see its own comment) until this
            // succeeds.
            const tileField = document.getElementById('two_tile_company');
            if (!tileField) {
                return;
            }
            this.companySearch = new TwoCompanySearch({
                checkoutHost: this.config.checkoutHost,
                // ALWAYS false in the payment tile, never inherited from the
                // merchant's general auto-fill toggle (core principle,
                // TWO-40: the control behaves identically wherever it is
                // mounted, with exactly one exception - it must never write
                // into the address form from here). The tile field
                // (#two_tile_company) is not part of the address form at
                // all, but autoFillAddress() writes into the address form's
                // OWN inputs (input[name='address1'/'postcode'/'city']) by
                // global selector, with no awareness of which control
                // triggered the fill - so leaving this inherited would let a
                // company picked in the tile silently rewrite an address the
                // buyer is not even looking at.
                addressLookupEnabled: false,
                companyFieldSelector: '#two_tile_company'
            });
            return;
        }
        this.companySearch = new TwoCompanySearch({
            checkoutHost: this.config.checkoutHost,
            addressLookupEnabled: this.config.addressLookupEnabled !== false,
            companyFieldSelector: "input[name='company']",
            // TWO-40: read through a getter, and injected rather than reached for
            // on `window`. This instance is constructed from inside the manager's
            // own constructor, so `window.TwoCheckoutManager_Instance` is not
            // assigned yet at that moment - a global lookup would find nothing on
            // the one call that matters, the mirror at mount time.
            getConfirmedCompany: () => this.getConfirmedCompanySelection(),
            // Page-lifetime, and deliberately the SAME object across every rebuild
            // of the search: it is what stops the mirror re-populating a field the
            // buyer cleared, and what lets it re-mark its own writes after core
            // rebuilds the address form.
            mirrorMemory: this._invoiceMirrorMemory
        });
    }

    /**
     * Undo the address form's search-mode affordance when no search will run
     * (TWO-25326). The placeholder is applied SERVER-side by the address-form
     * override, so unlike the dropdown and the results list it survives the
     * search control never being constructed - the buyer would be told to
     * "enter company name to search" on a field that is now just a text box.
     *
     * Only ever clears the module's OWN search wording, matched against the
     * translated string and its English source. A placeholder the theme (or
     * anything else) supplied is left exactly as it is.
     */
    neutralizeCompanySearchAffordance() {
        const ours = [
            this.t('company_search_placeholder', 'Enter company name to search'),
            'Enter company name to search'
        ];
        // ALL of them, though PrestaShop only ever renders one: the claim this
        // comment used to make - that a second editable address form with its own
        // `name='company'` appears once the buyer states the two addresses differ
        // - is wrong (TWO-40, verified against core). Core sets the delivery and
        // invoice form flags in mutually exclusive branches, so the other side is
        // always a radio selector over saved addresses, never a second form.
        // Walking all matches stays correct regardless, and costs nothing.
        document.querySelectorAll("input[name='company']").forEach(function (field) {
            const current = field.getAttribute('placeholder');
            if (current && ours.indexOf(current) !== -1) {
                field.removeAttribute('placeholder');
            }
        });
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
            //
            // TWO-25386 #8: `enabled` now follows the admin's order-intent
            // toggle (this.config.orderIntentEnabled) rather than being
            // hardcoded - shouldRunOrderIntent() reads it to skip the
            // pre-approval preview call entirely when the merchant has
            // turned it off. This is the pre-approval PREVIEW only; it never
            // gates the authoritative approval check the backend runs at
            // actual payment submission.
            this.orderIntent = new TwoOrderIntent({
                enabled: this.config.orderIntentEnabled !== false,
                orderIntentUrl: this.config.orderIntentUrl,
                ajaxToken: this.config.ajaxToken,
                enablePaymentPreventionOnDecline: true,
                // TWO-25326 §7.1: so collectFormData() knows not to trust the
                // address-area company/companyid DOM fields once search has
                // relocated to the tile.
                companySearchInAddressArea: this.config.companySearchInAddressArea !== false,
                // TWO-25326 bug 8: read through a getter rather than passed by
                // value, so the module always sees the CURRENT selection - this
                // instance is built once, on the first Two selection, and long
                // outlives any individual company choice.
                getConfirmedCompany: () => this.getConfirmedCompanySelection()
            });
        }
    }

    /**
     * The company the buyer has actually picked from the search results, as
     * published by TwoCompanySearch.onCompanySelected().
     *
     * Held HERE, not on the search instance, because the search instance does
     * not survive what the selection has to survive: PrestaShop replaces the
     * payment fragment (and with it the mounted search field) repeatedly while
     * the step settles, and handleAddressFormUpdate() destroys and rebuilds the
     * instance outright. This manager is a page-lifetime singleton
     * (window.TwoCheckoutManager_Instance), so a selection recorded here
     * outlives every re-render between the buyer's click and the intent check
     * it triggers.
     *
     * @returns {?{company: string, companyid: string, addressId: number}}
     */
    getConfirmedCompanySelection() {
        return this._confirmedCompanySelection || null;
    }

    /**
     * Adopt the server's cart-scoped company record as the confirmed selection,
     * when this page load has none of its own (TWO-40).
     *
     * The in-memory selection above is page-lifetime, and PrestaShop's address
     * step is a sequence of real document loads - the buyer states their invoice
     * address differs by following a link, which navigates. So on the page where
     * the invoice form finally appears, nothing the buyer picked earlier is in
     * memory any more, and every consumer of getConfirmedCompanySelection()
     * (the intent check's payload, the invoice-form mirror) is looking at null.
     *
     * The record arrives already guard-checked: the server publishes it only
     * through its validated read, so a selection whose country disagrees with
     * the cart's billing country, or which was captured against a different
     * address, is absent rather than seeded. The per-consumer checks
     * TwoOrderIntent.getConfirmedCompanySelection() applies still run on top of
     * it, unchanged - the captured address and country are carried through here
     * precisely so they can.
     *
     * Never overwrites a selection this page already has: an in-page pick is
     * newer than anything the server stored before the page loaded.
     *
     * @returns {void}
     */
    seedConfirmedCompanySelectionFromServer() {
        if (this._confirmedCompanySelection) {
            return;
        }
        const seed = this.config.confirmedCompany;
        if (!seed) {
            return;
        }
        const company = seed.company ? String(seed.company).trim() : '';
        const companyid = seed.companyid ? String(seed.companyid).trim() : '';
        if (!company || !companyid) {
            return;
        }
        this._confirmedCompanySelection = {
            company: company,
            companyid: companyid,
            // The server's own values, NOT re-derived from this page's DOM: the
            // point of the record is what was true when the buyer picked, and
            // re-reading the DOM here would stamp the selection with the address
            // and country of the page it is being restored onto - which would
            // defeat both invalidation checks it is meant to remain subject to.
            addressId: seed.address_id ? parseInt(seed.address_id, 10) : 0,
            countryIso: seed.country ? String(seed.country).toUpperCase() : ''
        };
    }

    /**
     * Record the buyer's confirmed company selection. Called from
     * TwoCompanySearch at the moment a result is picked and its organisation
     * number is known - including the deferred (GB) path, where the number only
     * arrives with the company-details lookup.
     *
     * The address currently selected at capture time is recorded alongside, so
     * TwoOrderIntent can drop the selection if the buyer later switches
     * address rather than credit-checking one address's company against
     * another.
     *
     * @param {?{company: string, companyid: string}} selection
     * @returns {void}
     */
    setConfirmedCompanySelection(selection) {
        const company = (selection && selection.company) ? String(selection.company).trim() : '';
        const companyid = (selection && selection.companyid) ? String(selection.companyid).trim() : '';
        if (!company || !companyid) {
            // Not a usable pair: forget any earlier one rather than leaving it
            // to be paired with a company the buyer has since moved off.
            this.clearConfirmedCompanySelection();
            return;
        }
        this._confirmedCompanySelection = {
            company: company,
            companyid: companyid,
            addressId: this.getSelectedAddressId(),
            countryIso: this.getSelectedCountryIso()
        };
    }

    /**
     * Forget the confirmed selection - manual entry, a cleared selection, or an
     * address edit, all of which mean the previously captured company can no
     * longer be assumed to be the buyer's.
     *
     * @returns {void}
     */
    clearConfirmedCompanySelection() {
        this._confirmedCompanySelection = null;
    }

    /**
     * The checkout address currently selected, or 0 when unknown.
     *
     * DELEGATES to TwoOrderIntent.getCurrentAddressId() whenever that module
     * exists (review round 1). The value captured here is compared against the
     * value THAT method resolves, so two independent resolutions with different
     * fallback orders would disagree on a page where both a hidden
     * `id_address_invoice` input and an open edit form are present - and a
     * disagreement reads as "the buyer switched address", silently throwing away
     * a valid selection and falling back to the very cookie path this exists to
     * avoid. The local fallback below is a byte-for-byte mirror of that method's
     * order for the case where the intent module has not been built yet.
     *
     * @returns {number}
     */
    getSelectedAddressId() {
        if (this.orderIntent && typeof this.orderIntent.getCurrentAddressId === 'function') {
            return this.orderIntent.getCurrentAddressId();
        }
        const checkedSelectors = [
            "input[name='id_address_invoice']:checked",
            "input[name='id_address_delivery']:checked"
        ];
        for (const selector of checkedSelectors) {
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
            const parsed = parseInt(addressForm.getAttribute('data-id-address') || '0', 10);
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

    /**
     * The billing country currently selected, as an ISO code, or '' when it
     * cannot be resolved - which at the payment step in tile mode is the norm,
     * because PrestaShop has removed the address form by then.
     *
     * Recorded alongside a confirmed selection so TwoOrderIntent can apply the
     * same country invalidation the session-cookie path applies.
     *
     * @returns {string}
     */
    getSelectedCountryIso() {
        if (this.orderIntent && typeof this.orderIntent.getCurrentAddressCountryISO === 'function') {
            return String(this.orderIntent.getCurrentAddressCountryISO() || '').toUpperCase();
        }
        const field = document.querySelector("select[name='id_country'], select[name='country']");
        if (field && field.selectedOptions && field.selectedOptions.length) {
            const option = field.selectedOptions[0];
            const iso = option.getAttribute('data-iso-code')
                || option.getAttribute('data-iso')
                || option.getAttribute('data-country-iso');
            if (iso) {
                return String(iso).toUpperCase();
            }
        }
        return '';
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
