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
            // TWO-25326 §7.1: whether the company-search control is in the
            // address area (true, default) or has relocated to the payment
            // tile (false). Gates whether collectFormData() may trust the
            // address form's `company`/`companyid` DOM fields at all - see
            // its own comment.
            companySearchInAddressArea: true,
            ...config
        };
        
        this.lastResult = null;
        this.isProcessing = false;
        this.checkIntervalId = null;
        this.lastCompany = null;
        // Retained alongside lastCompany so the tile label survives a payload
        // that omits the number; see publishPayloadCompany().
        this.lastCompanyNumber = null;
        // TWO-25326: monotonic sequence number for checkOrderIntent() calls.
        // reset() (called from TwoCompanySearch's onCompanySelected on every
        // fresh selection) forces isProcessing back to false so a re-search
        // is never blocked by an old in-flight check - but that alone let an
        // older, slower request for a PREVIOUSLY selected company overwrite
        // lastCompany/lastResult with stale data once it finally resolved,
        // racing a newer request started for the company actually selected
        // now. Bumped at the start of every checkOrderIntent() call; every
        // write of a call's result is gated on its own captured value still
        // matching this.requestSeq. Mirrors TwoCompanySearch.js's own
        // _companySearchSeq pattern for the identical race on its search
        // request.
        this.requestSeq = 0;
    }

    t(key, fallback) {
        if (window.twopayment && window.twopayment.i18n && window.twopayment.i18n[key]) {
            return window.twopayment.i18n[key];
        }
        return fallback;
    }

    /**
     * Is the order-intent APPROVED notice enabled for this brand? (TWO-25218)
     * Read from window.twopayment.intent_approved_notice_enabled
     * (brands/two.php -> Twopayment::isIntentApprovedNoticeEnabled), which the
     * PHP side emits as a real JS boolean.
     *
     * Only an explicit `false` turns the notice off. An absent key, or any
     * non-boolean value, reads as ENABLED - so an older cached JS file or an
     * older template that never carried this key can never mean off. That is
     * deliberate and must not be "tidied" into a truthiness check.
     */
    approvedNoticeEnabled() {
        const configured = window.twopayment ? window.twopayment.intent_approved_notice_enabled : null;
        return typeof configured === 'boolean' ? configured : true;
    }

    /**
     * Copy override for that notice (TWO-25218), read from
     * window.twopayment.intent_approved_notice.
     *
     *   null - platform default translated copy
     *   text - verbatim company-variant template, %s = company name
     *
     * Empty and whitespace-only resolve to null (default copy). This key does
     * NOT switch the notice off - approvedNoticeEnabled() does.
     */
    approvedNoticeOverride() {
        const configured = window.twopayment ? window.twopayment.intent_approved_notice : null;
        if (typeof configured !== 'string' || configured.trim() === '') {
            return null;
        }
        return configured;
    }

    isApprovedNoticeSuppressed() {
        return this.approvedNoticeEnabled() === false;
    }

    /**
     * Build the company-aware intent sentence (TWO-25326 §7.3, 2026-08-03
     * design ruling). Company name and number are folded directly into the
     * sentence - this is the single place that does it, so every caller
     * (this module's own processResult()/updateUI(), and
     * TwoCheckoutManager.handleOrderIntentResult()) renders identical wording.
     *
     * Omits the parenthesised number when none is known, matching the
     * "Example Ltd" over "Example Ltd ()" rule that governed the standalone
     * tile label this sentence replaces - that label is gone, not
     * supplemented.
     *
     * A brand override (`intent_approved_notice`, TWO-25218) stays name-only:
     * no PrestaShop brand overlay defines its own template today, so
     * extending the override format is out of scope here (see §7.4, which
     * does not apply to PS).
     *
     * @param {boolean} approved
     * @param {string} name
     * @param {string} number
     * @returns {string}
     */
    buildCompanyIntentMessage(approved, name, number) {
        // TWO-25326 §12: `TWO:`-prefixed internal identifiers are never shown.
        // Filtered HERE rather than at each of this method's three callers
        // (processResult, updateUI, TwoCheckoutManager.handleOrderIntentResult
        // - the last of which sources the number from the DOM, not from
        // lastCompanyNumber), because this is the single place the sentence is
        // built. The suppressed case falls through to the EXISTING
        // `_no_number` templates, so it reads "...by Example Ltd" and can
        // never render an empty pair of brackets.
        const displayNumber = window.TwoCompanyNumber.forDisplay(number);
        const hasNumber = displayNumber.length > 0;
        if (approved) {
            const override = this.approvedNoticeOverride();
            if (override !== null) {
                return TwoOrderIntent.fillTemplate(override, [name]);
            }
            const template = hasNumber
                ? this.t('invoice_likely_accepted_for', 'This order by %s (%s) is likely to be accepted by Two')
                : this.t('invoice_likely_accepted_for_no_number', 'This order by %s is likely to be accepted by Two');
            return TwoOrderIntent.fillTemplate(template, hasNumber ? [name, displayNumber] : [name]);
        }
        const template = hasNumber
            ? this.t('invoice_cannot_be_approved_for', 'Two is not available for this order by %s (%s)')
            : this.t('invoice_cannot_be_approved_for_no_number', 'Two is not available for this order by %s');
        return TwoOrderIntent.fillTemplate(template, hasNumber ? [name, displayNumber] : [name]);
    }

    /**
     * Fill `%s` placeholders in `template` from `values`, in order, by
     * position rather than by repeated `.replace()`.
     *
     * A sequential `template.replace('%s', a).replace('%s', b)` is unsafe the
     * moment `a` itself contains the literal text "%s": the first `.replace`
     * inserts a fresh "%s" into the string, and the second call's `.replace`
     * (which always matches the LEFTMOST occurrence) finds that one instead
     * of the real second placeholder - silently pairing `b` with the wrong
     * slot. Splitting the template up front and joining values back in
     * cannot re-match text that came from a substituted value, because the
     * split happens before any value is ever inserted.
     *
     * @param {string} template
     * @param {Array<string>} values
     * @returns {string}
     */
    static fillTemplate(template, values) {
        const parts = String(template).split('%s');
        let result = parts[0];
        const consumed = Math.min(values.length, parts.length - 1);
        for (let i = 0; i < consumed; i++) {
            result += values[i] + parts[i + 1];
        }
        // A template with MORE `%s` placeholders than supplied values (e.g. a
        // misconfigured brand override, TWO-25218, meant to carry one
        // placeholder but written with two) must not silently drop the rest
        // of the sentence - the leftover placeholders degrade to their
        // literal "%s" text instead of vanishing along with everything after
        // them.
        if (consumed < parts.length - 1) {
            result += '%s' + parts.slice(consumed + 1).join('%s');
        }
        return result;
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
    
    /**
     * Take the company out of the order-intent payload and publish it.
     *
     * Feeds the company-aware intent wording (TWO-25326 §7.3): the intent
     * message is the ONLY place the captured company appears in the tile as
     * of the 2026-08-03 design ruling - there is no separate label left to
     * feed.
     *
     * This is the module's own backend answering with the payload it built
     * server-side, from the session company that outlives the address form -
     * by the time the payment step exists PrestaShop has marked the address
     * step `-complete` and REMOVED that form, so reading `lastCompany`/
     * `lastCompanyNumber` from here rather than the (long gone) form inputs is
     * the only reliable source once the buyer has reached the payment step.
     *
     * A method rather than an inline block in the promise chain so it can be
     * tested without standing up the whole request pipeline.
     *
     * @param {?Object} payload Order-intent payload, as built by the backend.
     * @returns {void}
     */
    publishPayloadCompany(payload) {
        const company = (payload && payload.buyer && payload.buyer.company)
            ? payload.buyer.company
            : null;
        const name = (company && company.company_name)
            ? String(company.company_name).trim()
            : '';
        const number = (company && company.organization_number)
            ? String(company.organization_number).trim()
            : '';

        if (name) {
            this.lastCompany = name;
            // This payload's number, deliberately, and never a retained
            // earlier value: a payload carrying a name with no number (a
            // manual/sole-trader entry, name-only by design) must show the
            // name alone in the sentence, never pair it with a number left
            // over from a company the buyer has since moved off. Adversarial
            // review round 2 (TWO-25326): this was a real bug reintroduced
            // here - the two assignments used to be independent `if`s, which
            // is exactly the failure mode the deleted TwoCompanySummary.js's
            // own setIntentCompany() call was written to avoid.
            this.lastCompanyNumber = number || null;
        }
    }

    checkOrderIntent() {
        if (!this.shouldRunOrderIntent()) {
            return Promise.resolve(this.lastResult || { success: false, error: 'Order intent check skipped' });
        }
        this.isProcessing = true;
        // TWO-25326: this call's own sequence number - see the constructor
        // comment on requestSeq. Captured now, in a local `const`, so every
        // later check in this chain compares against whatever the NEWEST
        // call has bumped this.requestSeq to, not against a value that could
        // itself have gone stale.
        const seq = ++this.requestSeq;
        const isCurrent = () => seq === this.requestSeq;

        // CRITICAL FIX: Always let the backend try to resolve company data
        // The backend can check address fields (dni, companyid) that the frontend can't see
        // Backend will return appropriate status codes if company data is missing
        return this.collectFormData(seq)
            .then(formData => {
                // Always proceed to backend - it will check:
                // 1. Form data (company, companyid)
                // 2. Session cookie
                // 3. Address fields (dni, companyid) and verify via Two API
                // Backend returns status codes: 'no_company', 'incomplete_company' if needed
                return this.fetchOrderIntentPayload(formData);
            })
            .then(built => {
                // Superseded by a fresh selection while this request was in
                // flight - a slower response for a PREVIOUS company must
                // never publish that company as the current one.
                if (!isCurrent()) {
                    return this.lastResult || { success: false, error: 'Order intent check superseded' };
                }
                const payload = built ? built.payload : null;
                this.publishPayloadCompany(payload);
                // TWO-24799: the server recognised this exact decision snapshot
                // and returned the decision it already has, so skip the 2.5-3s
                // /v1/order_intent round trip. The server only does this when
                // every decision input (cart, addresses, country, company,
                // amounts) is byte-identical to the checked snapshot; anything
                // the buyer edits produces a different hash and no decision,
                // and the authoritative re-check still runs at payment submit.
                if (built && built.intentDecision) {
                    return {
                        success: true,
                        approved: !!built.intentDecision.approved,
                        message: '',
                        rawResponse: { approved: !!built.intentDecision.approved, deduped: true }
                    };
                }
                return this.callTwoOrderIntent(payload);
            })
            .then(result => {
                // Same guard on the way back out of callTwoOrderIntent()/the
                // dedup shortcut above - this is the write that would
                // otherwise overwrite a newer company's already-rendered
                // result.
                if (!isCurrent()) {
                    return this.lastResult || result;
                }
                return this.processResult(result);
            })
            .catch(error => {
                if (!isCurrent()) {
                    return this.lastResult || { success: false, approved: false, message: '' };
                }
                return this.handleError(error);
            })
            .finally(() => {
                // A stale request finishing must not clear isProcessing out
                // from under whichever newer request is still running - only
                // the request that IS the current sequence gets to flip it.
                if (isCurrent()) {
                    this.isProcessing = false;
                }
            });
    }

    /**
     * The company the buyer has ACTUALLY selected in this browser, if any, as
     * published by TwoCompanySearch through TwoCheckoutManager
     * (`getConfirmedCompany`, injected in initializeOrderIntent()).
     *
     * TWO-25326 bug 8. This exists because the request payload, not the
     * response ordering, was the stale half. In tile mode collectFormData()
     * deliberately reads NOTHING from the address-area DOM (see its comment)
     * and therefore always falls through to the `getCompany` round trip - which
     * reads the SESSION COOKIE. That cookie is written by
     * TwoCompanySearch.persistCompanyToCookie()'s own fire-and-forget
     * `saveCompany` request, issued in the same tick as the intent check that
     * follows it. A cookie written by a response the browser has not received
     * yet is not in the request it has already sent, so `getCompany` answers
     * with whatever was selected BEFORE this selection: nothing on the first
     * search (harmless - the server's own fallbacks resolve it), and the
     * PREVIOUS company on every search after that. The intent then fires,
     * legitimately and in order, for company A while the buyer is looking at
     * company B. No response-sequencing gate can see that, because the stale
     * request IS the current one.
     *
     * The selection the browser holds in memory needs no round trip and cannot
     * be stale, so it is the authoritative source. Returned only when it still
     * applies to the CURRENT checkout context - a country change or an address
     * switch invalidates a captured company, exactly as it does for the cookie
     * path below, and must not be smuggled past those checks by this shortcut.
     *
     * @returns {?{company: string, companyid: string}}
     */
    getConfirmedCompanySelection() {
        const getter = this.config.getConfirmedCompany;
        if (typeof getter !== 'function') {
            return null;
        }
        let selection = null;
        try {
            selection = getter();
        } catch (e) {
            return null;
        }
        if (!selection) {
            return null;
        }
        const company = selection.company ? String(selection.company).trim() : '';
        const companyid = selection.companyid ? String(selection.companyid).trim() : '';
        if (!company || !companyid) {
            return null;
        }
        // Same address-switch invalidation the session path applies (an
        // organisation number captured against one address must not be
        // credit-checked against another). `addressId` is the address that was
        // selected at capture time; 0 on either side means "unknown", which is
        // not evidence of a switch.
        const capturedAddressId = selection.addressId ? parseInt(selection.addressId, 10) : 0;
        const currentAddressId = this.getCurrentAddressId();
        if (capturedAddressId > 0 && currentAddressId > 0 && capturedAddressId !== currentAddressId) {
            return null;
        }
        // And the same country invalidation, for the same reason (review round
        // 1): the cookie path this shortcut preempts drops a stored company
        // whose country disagrees with the current address country
        // (`storedCountryMismatch`), and a shortcut that skipped that check
        // would be a weaker guard than the path it replaced. Compared only when
        // BOTH are resolvable - an unknown country on either side is not
        // evidence of a mismatch, and at the payment step in tile mode there is
        // often no country select in the DOM at all.
        const capturedCountry = selection.countryIso ? String(selection.countryIso).toUpperCase() : '';
        const currentCountry = String(this.getCurrentAddressCountryISO() || '').toUpperCase();
        if (capturedCountry && currentCountry && capturedCountry !== currentCountry) {
            return null;
        }
        return { company: company, companyid: companyid };
    }

    collectFormData(seq) {
        const isCurrent = () => seq === undefined || seq === this.requestSeq;
        return new Promise((resolve) => {
            const formData = {
                ajax: 1,
                action: 'checkOrderIntent',
                token: this.config.ajaxToken
            };
            let company = '';
            let companyid = '';
            // TWO-25326 §7.1: the address-area `company`/`companyid` DOM
            // fields are only a trustworthy source when the search control
            // actually lives there. In tile mode, `company` stays visible
            // and typeable BY DESIGN (never hidden - a real regression on
            // woocommerce-plugin) while `companyid` is the tile's OWN hidden
            // field elsewhere in the DOM - reading them here would let
            // whatever the buyer typed (or left blank) in that unrelated,
            // uncontrolled field silently override or mismatch-pair with
            // the company the buyer actually picked via the tile, since the
            // server's own resolver (getCompanyDataWithFallbacks()) treats
            // posted form data as HIGHER priority than the session the tile
            // selection persists. Leave both empty so the fallback below
            // always reaches for the session/cookie value instead.
            if (this.config.companySearchInAddressArea !== false) {
                const companyField = document.querySelector("input[name='company']");
                const companyIdField = document.querySelector("input[name='companyid']");
                company = companyField ? (companyField.value || '') : '';
                companyid = companyIdField ? (companyIdField.value || '') : '';
            }

            // If country changed since last selection, invalidate any existing values until a new selection is made
            let countryChanged = false;
            try { countryChanged = (sessionStorage.getItem('two_country_changed') === '1'); } catch (e) {}
            if (countryChanged) {
                company = '';
                companyid = '';
                // CRITICAL FIX: Clear the flag after handling country change to prevent it from persisting
                try { sessionStorage.removeItem('two_country_changed'); } catch (e) {}
            }

            // TWO-25326 bug 8: before reaching for the session cookie, use the
            // selection this browser is holding in memory - it needs no round
            // trip, so it cannot lag a `saveCompany` write that has not come
            // back yet. See getConfirmedCompanySelection() for why the cookie
            // read is systematically one selection behind in tile mode.
            //
            // Positioned AFTER the address-area DOM read and gated on it having
            // produced nothing, deliberately: where those fields ARE the
            // control (address mode), they carry what the buyer has typed
            // since, which is newer than any earlier confirmed selection and
            // must keep winning. In tile mode they are left empty by design a
            // few lines up, so this is always the path taken there. A pending
            // country change still invalidates a captured company, exactly as
            // it does for the cookie below - the shortcut must not smuggle one
            // past that.
            const confirmedSelection = countryChanged ? null : this.getConfirmedCompanySelection();
            if ((!company && !companyid) && confirmedSelection) {
                formData.company = confirmedSelection.company;
                formData.companyid = confirmedSelection.companyid;
                // Name and number written TOGETHER from the SAME source, and
                // only while this call is still the current one - same rule as
                // both branches below.
                if (isCurrent()) {
                    this.lastCompany = confirmedSelection.company;
                    this.lastCompanyNumber = confirmedSelection.companyid;
                }
                const confirmedAddressId = this.getCurrentAddressId();
                if (confirmedAddressId > 0) {
                    formData.id_address_invoice = confirmedAddressId;
                    formData.id_address_delivery = confirmedAddressId;
                }
                resolve(formData);
                return;
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
                        // Persist last company for messaging - name and number
                        // reassigned TOGETHER, from the SAME source (this
                        // session-fetch response), so the visible sentence
                        // (buildCompanyIntentMessage) can never pair a fresh
                        // name with a stale number left over from a
                        // different company. Gated on isCurrent() (TWO-25326):
                        // this AJAX round trip can resolve after the buyer has
                        // already selected a DIFFERENT company and started a
                        // newer checkOrderIntent() call - writing here anyway
                        // would stomp that newer call's own company right
                        // before it publishes its own result.
                        if (isCurrent()) {
                            this.lastCompany = formData.company;
                            this.lastCompanyNumber = formData.companyid || null;
                        }
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
            // Reassigned together, from the SAME (address-area DOM) read
            // above, for the same reason as the session-fetch branch above.
            this.lastCompany = company;
            this.lastCompanyNumber = companyid || null;
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
                        // TWO-24799: intent_decision is present only when the
                        // server matched an unchanged decision snapshot.
                        resolve({
                            payload: response.payload,
                            intentDecision: response.intent_decision || null
                        });
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
                    // TWO-25206: Two is the only thing that verifies the buyer's
                    // organization number now, so its rejection reason has to reach
                    // getErrorMessage() - jQuery's statusText alone ("Bad Request")
                    // carries none of it and every 4xx collapsed into the generic
                    // "pick another payment method" decline. Carry error_code and
                    // error_message through so COMPANY_NOT_FOUND (the response to an
                    // org number that does not resolve against the company registry)
                    // maps to the actionable company-search prompt instead.
                    reject(new Error(`Two order intent failed: ${TwoOrderIntent.describeApiError(xhr, error)}`));
                }
            });
        });
    }

    /**
     * Flatten an order-intent error response into a matchable string.
     * Reads the JSON body first (error_code / error_message), and falls back to
     * jQuery's statusText when the body is absent or not JSON.
     */
    static describeApiError(xhr, statusText) {
        let body = xhr && xhr.responseJSON ? xhr.responseJSON : null;

        if (!body && xhr && typeof xhr.responseText === 'string' && xhr.responseText !== '') {
            try {
                body = JSON.parse(xhr.responseText);
            } catch (e) {
                body = null;
            }
        }

        const parts = [];
        if (body && typeof body === 'object') {
            if (body.error_code) {
                parts.push('' + body.error_code);
            }
            if (body.error_message) {
                parts.push('' + body.error_message);
            }
        }
        if (parts.length === 0 && statusText) {
            parts.push('' + statusText);
        }

        return parts.join(' ');
    }

    processResult(response) {
        if (!response || typeof response !== 'object') {
            return { success: false, approved: false, message: this.t('invalid_response_from_server', 'Invalid response from server') };
        }
        const approvedSuppressed = !this.approvedNoticeEnabled();
        const result = {
            success: !!response.success,
            approved: !!response.approved,
            message: response.message || (response.approved
                ? this.t('invoice_likely_accepted', 'Your invoice with Two is likely to be accepted, subject to additional checks.')
                : this.t('invoice_cannot_be_approved', 'Your invoice with Two cannot be approved at this time')),
            rawResponse: response.rawResponse || response
        };
        // Approved notice switched off for this brand: carry no message at all
        // so nothing is rendered. Declines keep their message - they are
        // functional and drive setupOrderPrevention.
        if (result.approved && approvedSuppressed) {
            result.message = '';
        }
        // TWO-25326 §7.1: same reasoning as collectFormData() - the
        // address-area field is not a trustworthy source once search has
        // relocated to the tile (stays visible/typeable by design, but
        // uncontrolled). Only re-read it here in address-area mode.
        if (this.config.companySearchInAddressArea !== false) {
            const companyField = document.querySelector("input[name='company']");
            const fieldValue = companyField && companyField.value ? companyField.value : null;
            if (!this.lastCompany || fieldValue) {
                // Adversarial review round 2 (TWO-25326): a field value that
                // differs from what lastCompany already held means the buyer
                // retyped the name since that number was captured - clear
                // lastCompanyNumber too, rather than risk pairing a fresh
                // name with a stale number in the rendered sentence. An
                // unchanged value (the common case: this field is read-only
                // once a real search result is confirmed) is a no-op either
                // way, so this never discards a still-valid number.
                if (fieldValue && fieldValue !== this.lastCompany) {
                    this.lastCompanyNumber = null;
                }
                this.lastCompany = fieldValue || this.lastCompany;
            }
        }
        // Inject company into message immediately to ensure UI gets the contextual string
        if (this.lastCompany && typeof this.lastCompany === 'string' && this.lastCompany.trim().length > 0) {
            if (!result.approved) {
                result.message = this.buildCompanyIntentMessage(false, this.lastCompany, this.lastCompanyNumber);
            } else if (!approvedSuppressed) {
                result.message = this.buildCompanyIntentMessage(true, this.lastCompany, this.lastCompanyNumber);
            }
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

        // The backend (orderintent.php's no_company/incomplete_company
        // branches) already sends a complete, specific instruction telling
        // the buyer exactly what to fix - pass it through unchanged rather
        // than letting the keyword matching below fall through to the
        // generic defaultMessage, which would misleadingly tell them to
        // pick a *different* payment method instead of fixing the company
        // field that's actually blocking them.
        if (error.includes('select your company') || error.includes('search for your company')) {
            return errorString;
        }

        // TWO-25206: Two rejects the order intent with COMPANY_NOT_FOUND when the
        // organization number does not resolve against the company registry. This
        // is the case the plugin's own pre-verification used to catch locally, so
        // show the buyer the same instruction it used to show - matched before the
        // generic keyword branches below so the wording cannot drift into a vaguer
        // one. Kept ahead of 'not found' too, which is a broader catch-all.
        if (error.includes('company_not_found') || error.includes('company not found')) {
            return this.t(
                'select_company_to_use_two',
                'To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'
            );
        }

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
        // Notice switched off for this brand (TWO-25218): render no element at
        // all - not even an empty wrapper - and drop any element left over
        // from an earlier decline so a stale message cannot outlive an
        // approval. The functional part of an approval still runs below.
        if (result.approved && !this.approvedNoticeEnabled()) {
            $twoPaymentOption.find('.two-order-intent-message').remove();
            $twoPaymentOption.removeClass('disabled');
            $twoPaymentOption.find('input[type="radio"]').prop('disabled', false);
            return;
        }
        let $messageContainer = $twoPaymentOption.find('.two-order-intent-message');
        if ($messageContainer.length === 0) {
            $messageContainer = $('<div class="two-order-intent-message"></div>');
            $twoPaymentOption.find('.payment-option-content, .payment-form, .additional-information').append($messageContainer);
        }
        let messageText = result.message;
        // Only template in the company name for a real decision FROM Two's
        // API (processResult() always sets rawResponse). handleError()'s
        // results - the local no_company/incomplete_company guard, or a
        // network failure - never set it, and already carry a specific,
        // actionable message (e.g. "search for your company name and
        // select it from the results"); templating those into a generic
        // "cannot be approved for <company>" here would silently discard
        // that specific guidance.
        if (
            result.rawResponse &&
            this.lastCompany &&
            typeof this.lastCompany === 'string' &&
            this.lastCompany.trim().length > 0
        ) {
            messageText = this.buildCompanyIntentMessage(!!result.approved, this.lastCompany, this.lastCompanyNumber);
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

        // Theme-independent nudge: reuse the same inline message element
        // updateUI() already keeps in the Two payment option (present on
        // every PS theme, unlike window.prestashop.notification - a PS9
        // Bootstrap-5 API absent on PS 8's classic theme). Previously this
        // fell back to a blocking native alert() on themes without that
        // API, which PS 8's e2e run confirmed actually fires - a jarring
        // native dialog Playwright silently auto-dismisses, so the
        // message never even reaches the DOM there.
        let $messageContainer = $twoPaymentOption.find('.two-order-intent-message');
        if ($messageContainer.length === 0) {
            $messageContainer = $('<div class="two-order-intent-message"></div>');
            $twoPaymentOption.find('.payment-option-content, .payment-form, .additional-information').append($messageContainer);
        }
        $messageContainer.removeClass('approved loading').addClass('declined').text(message);
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
                // Backend can find org numbers in address fields (dni, companyid) 
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
        // Cleared with its name: a retained number outliving the reset could
        // only ever be paired with a DIFFERENT company's name later.
        this.lastCompanyNumber = null;
        this.isProcessing = false;
        // TWO-25326: invalidate any request already in flight for whatever
        // was selected before this reset, independent of whether a new
        // checkOrderIntent() call follows immediately. Without this, a
        // caller that resets but does not immediately re-check (e.g. blocked
        // by triggerOrderIntentForSelection()'s own cooldown) left the old
        // request "current" - so it would still land and overwrite the just-
        // cleared state with a stale company's result once it resolved.
        this.requestSeq += 1;
        this.stopMonitoring();
    }
}

window.TwoOrderIntent = TwoOrderIntent;
