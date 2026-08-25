/**
 * Two Checkout Manager - theme-independent, using native PrestaShop events
 * and patterns.
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
    
    init() {
        this.detectCheckoutStep();
        this.detectAccountType();
        this.initializeModules();
        this.setupPrestaShopEventListeners();

        // The company-search control mounted (initializeModules(), above) while
        // its tile may still have been collapsed by core, so in tile mode it
        // measured a field inside a hidden container. Re-measure at the end of
        // init, so the control is laid out from a real measurement on this load
        // rather than on the next viewport change.
        this.remeasureCompanySearchLayout();

        this.isInitialized = true;
    }
    
    detectCheckoutStep() {
        // URL path is the most reliable signal for PrestaShop.
        const currentPath = window.location.pathname;
        if (currentPath.includes('/order')) {
            const urlParams = new URLSearchParams(window.location.search);
            const step = urlParams.get('step');
            if (step) {
                this.currentStep = step;
                return;
            }
        }
        
        const bodyClasses = document.body.className;

        const controllerMatch = bodyClasses.match(/controller-(\w+)/);
        if (controllerMatch && controllerMatch[1] === 'order') {
            if (document.querySelector('.payment-options')) {
                this.currentStep = 'payment';
            } else if (document.querySelector('.js-address-form, form[name*="address"], form[name*="customer"]')) {
                this.currentStep = 'address';
            } else {
                this.currentStep = 'checkout';
            }
            return;
        }
        
        // One-page checkout.
        if (bodyClasses.includes('order-opc') || bodyClasses.includes('checkout')) {
            if (document.querySelector('.payment-options')) {
                this.currentStep = 'payment';
            } else {
                this.currentStep = 'address';
            }
            return;
        }
        
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

        if (this.twoPaymentOption) {
            this.twoPaymentRadio = this.twoPaymentOption.querySelector('input[type="radio"]');
        }
    }

    detectTwoPaymentOption() {
        let paymentOption = document.querySelector('[data-module-name="twopayment"]');
        if (paymentOption) {
            return paymentOption;
        }

        paymentOption = document.querySelector('.payment-option input[data-module-name="twopayment"]');
        if (paymentOption) {
            paymentOption = paymentOption.closest('.payment-option');
            if (paymentOption) {
                return paymentOption;
            }
        }
        
        const paymentForms = document.querySelectorAll('.payment-option form, form[action*="twopayment"]');
        for (const form of paymentForms) {
            if (form.action && form.action.includes('twopayment')) {
                paymentOption = form.closest('.payment-option') || form.closest('.payment-option-container') || form.parentElement;
                if (paymentOption) {
                    return paymentOption;
                }
            }
        }
        
        const allPaymentOptions = document.querySelectorAll('.payment-option, [class*="payment-option"], [id*="payment-option"]');
        for (const option of allPaymentOptions) {
            const optionText = option.textContent || '';
            const hasLogo = option.querySelector('img[src*="two"], img[alt*="two" i], img[alt*="Two"]');
            const hasTwoText = optionText.toLowerCase().includes('two') &&
                              (optionText.toLowerCase().includes('pay') || optionText.toLowerCase().includes('invoice'));

            if (hasLogo || hasTwoText) {
                const hasTwoContainer = option.querySelector('.two-payment-container, .two-payment-info, #two-payment-terms');
                if (hasTwoContainer) {
                    return option;
                }
            }
        }

        const twoContainer = document.querySelector('.two-payment-container');
        if (twoContainer) {
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
    
    setupPrestaShopEventListeners() {
        if (typeof prestashop !== 'undefined') {
            prestashop.on('updatedAddressForm', () => {
                this.handleAddressFormUpdate();
            });

            prestashop.on('updatedDeliveryForm', () => {
                this.handleDeliveryFormUpdate();
            });

            prestashop.on('updatedPaymentForm', () => {
                this.handlePaymentFormUpdate();
            });

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

        this.setupPaymentOptionSelectionListener();
        this.setupMutationObserver();
    }

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
        
        document.addEventListener('click', (event) => {
            if (this.isPaymentConfirmationButton(event.target)) {
                this.handlePaymentConfirmation(event);
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (this.isPaymentConfirmationForm(form)) {
                this.handlePaymentConfirmation(event);
            }
        });

        // Periodic fallback for themes where none of the above listeners fire.
        this._selectionCheckInterval = setInterval(() => {
            this.detectCheckoutStep();
            if (this.currentStep !== 'payment') {
                return;
            }
            if (this.isTwoPaymentSelected() && this.config.orderIntentEnabled && this.canAutoTriggerOrderIntent()) {
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
        const isTwoSelected = this.isTwoPaymentSelected(radioButton);

        // Mirror the buyer surcharge as a real PrestaShop cart line (add on
        // Two selection, remove on any other selection). Server-side endpoint
        // is idempotent, so repeated change events are harmless.
        this.syncSurchargeCartLine(isTwoSelected);

        if (isTwoSelected && this.config.orderIntentEnabled && this.canAutoTriggerOrderIntent()) {
            if (!this.orderIntent && window.TwoOrderIntent) {
                this.initializeOrderIntent();
            }
            if (this.orderIntent) {
                this.triggerOrderIntentForSelection();
            }
        } else if (this.orderIntent) {
            this.clearOrderIntentUI();
            // Reset so a switch back to Two re-checks rather than reusing a stale result.
            if (this.orderIntent && this.orderIntent.lastResult) {
                this.orderIntent.lastResult = null;
            }
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
     * WITHOUT navigating the document (TWO-25326 bug 11).
     *
     * Deliberately does NOT emit core's own `updateCart` event: on the payment
     * step core turns that into a full page navigation
     * (`refreshCheckoutPage()` in `themes/_core/js/common.js`), which is what
     * caused the tile to visibly flicker away and back. Instead this POSTs
     * the same `.js-cart` refresh URL core's handler uses and swaps the same
     * summary partials out of the response, without navigating.
     *
     * Giving up the navigation's side effect (other payment modules
     * recomputing against the new amount) is safe: nothing here needs it. The
     * fee line only exists while Two is selected, is removed the instant
     * another option is picked, and is additionally stripped server-side
     * before any other payment module computes totals (the
     * `actionFrontControllerInitAfter` stale-guard). The order-create parity
     * gate remains the authoritative check either way.
     *
     * FAIL-SOFT: a theme with no `.js-cart` refresh URL, or a failed request,
     * leaves the summary showing its previous total - display staleness
     * only, never a charge mismatch.
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
            // FIRST match only, deliberately. Every one of these
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
     * Check if Two payment is selected
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
     * Re-run the intent check for a company the buyer has JUST chosen.
     *
     * triggerOrderIntentForSelection() reuses `lastResult` whenever it has one,
     * so a new choice has to invalidate that first or the tile keeps showing the
     * PREVIOUS company's answer. Every path that changes which entity the buyer
     * is goes through here (TWO-25503): a search re-selection, a switch into
     * sole-trader mode that adopts an identity, and "select a different sole
     * trader".
     *
     * @returns {void}
     */
    recheckOrderIntentForNewSelection() {
        if (typeof this.isTwoPaymentSelected !== 'function' || !this.isTwoPaymentSelected()) {
            return;
        }
        // triggerOrderIntentForSelection() dereferences this.orderIntent
        // unguarded on its last branch. Nothing to invalidate before one exists
        // anyway, and the periodic selection check picks the buyer's choice up
        // once the module is built.
        if (!this.orderIntent || typeof this.orderIntent.reset !== 'function') {
            return;
        }
        this.orderIntent.reset();
        this.triggerOrderIntentForSelection();
    }

    triggerOrderIntentForSelection() {
        if (this.currentStep !== 'payment' || !this.twoPaymentOption) {
            return;
        }

        if (!this.isTwoPaymentSelected()) {
            return;
        }

        // Reuse the existing result rather than re-checking, which would loop
        // against the periodic selection check.
        if (this.orderIntent && this.orderIntent.lastResult && this.orderIntent.lastResult.success !== undefined) {
            this.handleOrderIntentResult(this.orderIntent.lastResult);
            return;
        }

        if (this.orderIntent && this.orderIntent.isProcessing) {
            this.showOrderIntentLoading();
            return;
        }
        const now = Date.now();
        if (now - this._lastIntentRunAt < this._intentCooldownMs) {
            return;
        }
        this._lastIntentRunAt = now;

        this.showOrderIntentLoading();

        // Not ready yet: keep showing loading and retry shortly.
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
            const status = result.status || '';
            const err = (result && result.error) ? String(result.error) : '';
            const errLower = err.toLowerCase();

            // 'no_company' = no company name entered at all
            // 'incomplete_company' = company name exists but backend couldn't auto-resolve org number
            if (status === 'no_company') {
                this.showCompanyRequiredMessage(err, status);
                return;
            }

            if (status === 'incomplete_company') {
                this.showCompanyRequiredMessage(err, status);
                return;
            }

            // Legacy: order intent skipped frontend-side, before the status-code path existed.
            if (errLower.includes('skipped_no_company')) {
                this.showCompanyRequiredMessage(err, 'no_company');
                return;
            }

            if (errLower.includes('skipped')) {
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

        // Saved server-side too so disabling JavaScript can't bypass the client-side block.
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
     * @param {string} status 'no_company' or 'incomplete_company'
     */
    showCompanyRequiredMessage(message, status) {
        if (this.suppressCompanyRelocationPrompt()) {
            return;
        }
        const messageContainer = this.getOrCreateMessageContainer();
        const actionTitle = this.t('action_required_title', 'Action Required');

        let helpText = '';
        if (status === 'no_company') {
            helpText = this.t(
                'company_name_required',
                'To pay with Two, go back to your billing address and enter your company name in the Company field.'
            );
        } else if (status === 'incomplete_company') {
            helpText = this.t(
                'select_company_to_use_two',
                'To pay with Two, go back to your billing address and search for your company name. Select your company from the search results to verify your business.'
            );
        }
        
        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.textContent = message || helpText;
        } else {
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

        messageContainer.classList.remove('approved', 'loading', 'declined');
        messageContainer.classList.add('show', 'action-required');
        messageContainer.style.display = 'block';

        this.hideLoadingOverlay();
    }

    /**
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

        // Some themes render a template-side loader in paymentinfo.tpl too.
        try {
            const templateLoader = document.querySelector('.two-payment-container .two-loading-container');
            if (templateLoader) {
                templateLoader.style.display = 'flex';
            }
        } catch (e) { /* noop */ }
    }

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

        messageContainer.classList.remove('declined', 'loading');
        messageContainer.classList.add('approved', 'show');
        messageContainer.style.display = 'block';

        this.clearLoadingState();
        this.hideLoadingOverlay();
        this.showPaymentTerms();
    }

    showOrderIntentDecline(message) {
        const messageContainer = this.getOrCreateMessageContainer();

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

        messageContainer.classList.remove('approved', 'loading');
        messageContainer.classList.add('declined', 'show');
        messageContainer.style.display = 'block';

        this.clearLoadingState();
        this.hideLoadingOverlay();
    }

    /**
     * If company data is missing, show company-specific guidance instead of a
     * generic error - checked first so a real error with company data present
     * still surfaces below.
     */
    showOrderIntentError(error) {
        const companyMissing = this.isCompanyDataMissing();

        let userFriendlyError;
        if (companyMissing) {
            // The tile's own search control is what prompts "go search for your
            // company" once it lives here, so there is nothing left to render.
            if (this.suppressCompanyRelocationPrompt()) {
                return;
            }
            userFriendlyError = this.t(
                'select_company_to_use_two',
                'To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'
            );
        } else {
            userFriendlyError = this.t(
                'generic_error',
                'There was an issue processing your Two payment request. Please try again or choose another payment method.'
            );
        }

        const messageContainer = this.getOrCreateMessageContainer();

        const messageElement = messageContainer.querySelector('.two-payment-message') || messageContainer;
        if (messageElement !== messageContainer) {
            messageElement.textContent = userFriendlyError;
        } else {
            messageContainer.innerHTML = `
                <p class="two-subtitle">${this.escapeHtml(this.t('action_required_title', 'Action Required'))}</p>
                <p class="two-payment-message">${this.escapeHtml(userFriendlyError)}</p>
            `;
        }

        messageContainer.classList.remove('approved', 'loading', 'declined');
        messageContainer.classList.add('show');
        messageContainer.style.display = 'block';

        this.clearLoadingState();
        // TWO-25503: clearing only the in-flight FLAG left the overlay and the
        // template-side loader on screen, covering the message this method has
        // just rendered - the buyer saw an endless spinner and no explanation.
        this.hideLoadingOverlay();
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
        // this method's docblock, consulted before the page-lifetime selection
        // below: while the address form is on screen the DOM field is the live
        // one, and the seeded selection stands in for it only once the field is
        // empty or gone.
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

    getUserFriendlyErrorMessage(error) {
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
    
    clearLoadingState() {
        // No-op: no longer toggles visual state classes on the payment option.
    }

    hideLoadingOverlay() {
        this.isLoadingUIShown = false;
        if (!this.twoPaymentOption) return;
        const parent = this.twoPaymentOption.querySelector('.payment-option-content') || this.twoPaymentOption;
        const overlay = parent.querySelector('#two-loading-overlay');
        if (overlay) {
            overlay.classList.remove('show');
        }

        try {
            const templateLoader = document.querySelector('.two-payment-container .two-loading-container');
            if (templateLoader) {
                templateLoader.style.display = 'none';
            }
        } catch (e) { /* noop */ }
    }

    clearOrderIntentUI() {
        const messageContainer = document.querySelector('#two-order-intent-messages');
        if (messageContainer) {
            messageContainer.style.display = 'none';
            messageContainer.innerHTML = '';
        }
        this.hidePaymentTerms();
        this.clearLoadingState();
    }

    hidePaymentTerms() {
        const termsContainer = document.querySelector('#two-payment-terms');
        if (termsContainer) {
            termsContainer.classList.remove('show');
            // Timeout lets the collapse animation finish before hiding.
            setTimeout(() => {
                termsContainer.style.display = 'none';
            }, 300);
        }
    }
    
    getOrCreateMessageContainer() {
        let container = document.querySelector('.two-payment-info');

        if (container) {
            container.style.display = 'block';
            container.classList.add('show');
            return container;
        }

        container = document.querySelector('#two-order-intent-messages');
        if (!container) {
            container = document.createElement('div');
            container.id = 'two-order-intent-messages';
            container.className = 'two-order-intent-messages';
            container.style.marginTop = '10px';

            const twoContainer = document.querySelector('.two-payment-container');
            if (twoContainer) {
                twoContainer.appendChild(container);
            } else if (this.twoPaymentOption) {
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

    // Tries several selectors in turn since the terms container's location
    // varies by theme/template version.
    showPaymentTerms() {
        let termsContainer = document.querySelector('#two-payment-terms');

        if (!termsContainer) {
            termsContainer = document.querySelector('.two-payment-terms');
        }

        if (!termsContainer) {
            const twoContainer = document.querySelector('.two-payment-container');
            if (twoContainer) {
                termsContainer = twoContainer.querySelector('[id*="payment-terms"], [class*="payment-terms"]');
            }
        }

        if (!termsContainer && this.twoPaymentOption) {
            termsContainer = this.twoPaymentOption.querySelector('[id*="payment-terms"], [class*="payment-terms"]');
        }

        if (!termsContainer) {
            const additionalInfo = document.querySelector('#payment-option-1-additional-information, .additional-information, .js-additional-information');
            if (additionalInfo) {
                termsContainer = additionalInfo.querySelector('[id*="payment-terms"], [class*="payment-terms"]');
            }
        }

        if (termsContainer) {
            termsContainer.style.display = 'block';
            termsContainer.style.visibility = 'visible';
            termsContainer.style.opacity = '1';

            // Force a reflow before adding the show class, or the animation doesn't play.
            termsContainer.offsetHeight;
            termsContainer.classList.add('show');

            this.initializePaymentTerms();
        } else {
            console.warn('Two Payment: Payment terms container not found - template may not be rendered');
            this.injectPaymentTermsIfMissing();
        }
    }

    injectPaymentTermsIfMissing() {
        let targetContainer = this.getOrCreateMessageContainer();

        if (!targetContainer) {
            console.error('Two Payment: Cannot inject payment terms - no target container found');
            return;
        }

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
        
        targetContainer.insertAdjacentHTML('afterend', termsHtml);
        this.initializePaymentTerms();
    }

    initializePaymentTerms() {
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

        if (termsContainer.hasChildNodes() && termsContainer.querySelector('.two-term-chip')) {
            return;
        }

        if (termsContainer.hasChildNodes()) {
            termsContainer.innerHTML = '';
        }

        const availableTerms = this.config.available_payment_terms;
        const configuredDefaultTerm = this.config.default_payment_term;
        const termType = this.config.payment_term_type || 'STANDARD';

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
                termsContainer.querySelectorAll('.two-term-chip').forEach(chip => {
                    chip.classList.remove('two-term-chip--selected');
                    chip.setAttribute('aria-checked', 'false');
                });

                termChip.classList.add('two-term-chip--selected');
                termChip.setAttribute('aria-checked', 'true');

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
            success: () => {},
            error: (xhr, status, error) => {
                // Non-blocking: client-side blocking still works without the server copy.
                console.warn('TwoPayment: Failed to save order intent result to server:', error);
            }
        });
    }

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
            success: () => {},
            error: () => {
                console.warn('TwoPayment: Failed to clear order intent result from server');
            }
        });
    }

    disableTwoPayment() {
        // No custom styles/classes on the payment option - keep this minimal.
        if (this.twoPaymentRadio) {
            this.twoPaymentRadio.disabled = true;
        }
    }

    enableTwoPayment() {
        if (this.twoPaymentRadio) {
            this.twoPaymentRadio.disabled = false;
        }
    }
    
    setupMutationObserver() {
        if (this._mutationObserver) {
            this._mutationObserver.disconnect();
            this._mutationObserver = null;
        }

        this._mutationObserver = new MutationObserver((mutations) => {
            let shouldReinitialize = false;

            mutations.forEach((mutation) => {
                if (mutation.type === 'childList') {
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
    
    handleDynamicContentChange() {
        this.detectCheckoutStep();
        const previousPaymentOption = this.twoPaymentOption;
        this.detectAccountType();

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

        // Deliberately NOT re-running setupPaymentOptionSelectionListener() here:
        // every listener it binds is delegated to `document`, not to any node
        // inside the replaced fragment, so it keeps matching events across any
        // number of DOM replacements without re-attaching. Re-running it would
        // add a fresh, permanent set of duplicate document-level listeners (plus
        // another polling setInterval, never cleared) on every firing - and
        // cleanup() has no matching removeEventListener calls to undo that once
        // bound, since the handlers are anonymous closures never kept.

        if (this.isBusinessAccount && this.config.orderIntentEnabled && !this.orderIntent) {
            this.initializeOrderIntent();
        }

        if (this.currentStep === 'payment' && this.isTwoPaymentSelected() && this.config.orderIntentEnabled && this.canAutoTriggerOrderIntent()) {
            setTimeout(() => {
                if (this.orderIntent && !this.isLoadingUIShown) {
                    this.triggerOrderIntentForSelection();
                }
            }, 500);
        }
    }


    handleAddressFormUpdate() {
        this.detectCheckoutStep();
        this.detectAccountType();

        // Tile mode (TWO-25326 §7.1) does nothing here deliberately: the address
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

        if (this.orderIntent && this.orderIntent.reset) {
            this.orderIntent.reset();
        }
        this.clearOrderIntentUI();
        this.clearOrderIntentResultFromServer();
        this.enableTwoPayment();
    }

    handleDeliveryFormUpdate() {
        this.detectCheckoutStep();
    }

    handlePaymentFormUpdate() {
        this.detectAccountType();
        this.handleDynamicContentChange();

        if (this.orderIntent && this.orderIntent.lastResult) {
            this.orderIntent.lastResult = null;
        }

        if (this.config.orderIntentEnabled) {
            if (!this.orderIntent && window.TwoOrderIntent) {
                this.initializeOrderIntent();
            }

            if (this.isTwoPaymentSelected() && this.orderIntent && this.canAutoTriggerOrderIntent()) {
                // Small delay to let the DOM settle.
                setTimeout(() => {
                    this.triggerOrderIntentForSelection();
                }, 300);
            }
        }
    }

    handleCheckoutEvent(event) {
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

    handlePaymentConfirmation(event) {
        this.detectCheckoutStep();
        if (this.currentStep !== 'payment') {
            return;
        }

        if (this.isTwoPaymentSelected() && this.orderIntent && this.config.orderIntentEnabled) {
            if (this.orderIntent.isProcessing || !this.orderIntent.lastResult) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                this.showOrderIntentLoading();
                this.triggerOrderIntentForSelection();
                return;
            }
            if (this.orderIntent.lastResult && !this.orderIntent.lastResult.approved) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                const msg = this.t('approval_required', 'Payment approval required before proceeding');
                this.showOrderIntentError(msg);
            }
        }
    }
    
    initializeModules() {
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
        // Queries ALL matches, though PrestaShop only ever renders one (TWO-40,
        // verified against core: delivery/invoice form flags are mutually
        // exclusive, so the other side is always a radio selector over saved
        // addresses, never a second `name='company'` form). Costs nothing either way.
        document.querySelectorAll("input[name='company']").forEach(function (field) {
            const current = field.getAttribute('placeholder');
            if (current && ours.indexOf(current) !== -1) {
                field.removeAttribute('placeholder');
            }
        });
    }

    initializeOrderIntent() {
        if (!this.orderIntent && window.TwoOrderIntent) {
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
        // TWO-25503: mirror of TwoOrderIntent.getCurrentAddressId() - see there
        // for why the editable form outranks the saved-address radios.
        const editableAddressForm = document.querySelector("input[name='saveAddress']");
        if (editableAddressForm) {
            const form = (typeof editableAddressForm.closest === 'function'
                ? editableAddressForm.closest('form[data-id-address]')
                : null) || document.querySelector('.js-address-form form[data-id-address]');
            const parsed = form ? parseInt(form.getAttribute('data-id-address') || '0', 10) : 0;
            return parsed > 0 ? parsed : 0;
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
    
    isTwoPaymentAvailable() {
        return !!this.twoPaymentOption;
    }

    triggerOrderIntent() {
        if (this.orderIntent) {
            return this.orderIntent.checkOrderIntent();
        }
        return Promise.resolve({ success: false, error: 'Order intent not available' });
    }

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
    
    cleanup() {
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
        
        this._paymentListenersAttached = false;
        this._lastSelectionCheck = 0;
        this.isInitialized = false;

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
