class TwoOrderIntent {
    constructor(config) {
        this.config = {
            enabled: false,
            orderIntentUrl: '',
            ajaxToken: '',
            enablePaymentPreventionOnDecline: true,
            // TWO-25326 §7.1: gates whether collectFormData() may trust the
            // address form's `company`/`companyid` DOM fields at all.
            companySearchInAddressArea: true,
            // The control whose block this module's address-id reads must agree
            // with - see getCurrentAddressId().
            getCompanySearch: null,
            ...config
        };
        
        this.lastResult = null;
        this.isProcessing = false;
        this.checkIntervalId = null;
        this.lastCompany = null;
        // Retained so the tile label survives a payload that omits the number.
        this.lastCompanyNumber = null;
        // TWO-25326: monotonic sequence number, bumped at the start of every
        // checkOrderIntent() call. Every write of a call's result is gated on
        // its own captured value still matching this.requestSeq, so an older,
        // slower request for a PREVIOUSLY selected company cannot overwrite
        // lastCompany/lastResult once it finally resolves.
        this.requestSeq = 0;
    }

    t(key, fallback) {
        if (window.twopayment && window.twopayment.i18n && window.twopayment.i18n[key]) {
            return window.twopayment.i18n[key];
        }
        return fallback;
    }

    /**
     * TWO-25218. Only an explicit `false` turns the notice off. An absent key,
     * or any non-boolean value, reads as ENABLED - so an older cached JS file
     * or an older template that never carried this key can never mean off.
     * Must not be "tidied" into a truthiness check.
     */
    approvedNoticeEnabled() {
        const configured = window.twopayment ? window.twopayment.intent_approved_notice_enabled : null;
        return typeof configured === 'boolean' ? configured : true;
    }

    /**
     * TWO-25218 copy override: null = platform default translated copy, text =
     * verbatim company-variant template with %s = company name. Empty and
     * whitespace-only resolve to null. This key does NOT switch the notice
     * off - approvedNoticeEnabled() does.
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
     * TWO-25326 §7.3. The single place the company name and number are folded
     * into the sentence, so every caller renders identical wording. Omits the
     * parenthesised number when none is known ("Example Ltd", never
     * "Example Ltd ()").
     *
     * A brand override (`intent_approved_notice`, TWO-25218) stays name-only:
     * no PrestaShop brand overlay defines its own template today.
     */
    buildCompanyIntentMessage(approved, name, number) {
        // TWO-25326 §12: `TWO:`-prefixed internal identifiers are never shown.
        // The suppressed case falls through to the `_no_number` templates, so
        // it can never render an empty pair of brackets.
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
     * By position rather than by repeated `.replace()`: a sequential
     * `template.replace('%s', a).replace('%s', b)` is unsafe the moment `a`
     * itself contains the literal text "%s", because the second `.replace`
     * matches the leftmost occurrence - the one just inserted - and pairs `b`
     * with the wrong slot. Splitting up front cannot re-match text that came
     * from a substituted value.
     */
    static fillTemplate(template, values) {
        const parts = String(template).split('%s');
        let result = parts[0];
        const consumed = Math.min(values.length, parts.length - 1);
        for (let i = 0; i < consumed; i++) {
            result += values[i] + parts[i + 1];
        }
        // A template with MORE `%s` placeholders than supplied values (a
        // misconfigured brand override, TWO-25218) must not silently drop the
        // rest of the sentence - the leftover placeholders degrade to their
        // literal "%s" text.
        if (consumed < parts.length - 1) {
            result += '%s' + parts.slice(consumed + 1).join('%s');
        }
        return result;
    }

    /**
     * Query params rather than a body field, even though the order-intent call
     * is a POST with a JSON body: that is the convention the module's own
     * server-side calls already use for this pair (getTwoClientParams() /
     * setTwoPaymentRequest() in twopayment.php). Putting them in the body would
     * also change a payload the server builds and Two validates.
     *
     * Either param is dropped when the config does not carry it, so a page that
     * somehow runs without the config sends a correct URL rather than a literal
     * `client=undefined`.
     */
    static withTwoClientParams(url) {
        const config = (typeof window !== 'undefined' && window.twopayment) || {};
        const params = new URLSearchParams();
        if (config.client) {
            params.set('client', config.client);
        }
        if (config.client_version) {
            params.set('client_v', config.client_version);
        }
        const query = params.toString();
        if (!query) {
            return url;
        }

        return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
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
     * Feeds the company-aware intent wording (TWO-25326 §7.3), the only place
     * the captured company appears in the tile.
     *
     * The payload comes from the module's own backend, built from the session
     * company that outlives the address form - by the time the payment step
     * exists PrestaShop has marked the address step `-complete` and REMOVED
     * that form, so this is the only reliable source there.
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
            // This payload's number, never a retained earlier value: a payload
            // carrying a name with no number (a manual/sole-trader entry,
            // name-only by design) must show the name alone, never pair it
            // with a number from a company the buyer has since moved off.
            this.lastCompanyNumber = number || null;
        }
    }

    checkOrderIntent() {
        if (!this.shouldRunOrderIntent()) {
            return Promise.resolve(this.lastResult || { success: false, error: 'Order intent check skipped' });
        }
        this.isProcessing = true;
        // TWO-25326: captured in a local `const` so every later check in this
        // chain compares against whatever the NEWEST call has bumped
        // this.requestSeq to.
        const seq = ++this.requestSeq;
        const isCurrent = () => seq === this.requestSeq;

        // Always let the backend resolve company data: it can check address
        // fields (dni, companyid) the frontend cannot see, and answers with
        // 'no_company'/'incomplete_company' when it finds nothing.
        return this.collectFormData(seq)
            .then(formData => {
                return this.fetchOrderIntentPayload(formData);
            })
            .then(built => {
                // A slower response for a PREVIOUS company must never publish
                // that company as the current one.
                if (!isCurrent()) {
                    return this.lastResult || { success: false, error: 'Order intent check superseded' };
                }
                const payload = built ? built.payload : null;
                this.publishPayloadCompany(payload);
                // TWO-24799: the server recognised this exact decision snapshot
                // and returned the decision it already has, so skip the 2.5-3s
                // /v1/order_intent round trip. Only happens when every decision
                // input is byte-identical to the checked snapshot, and the
                // authoritative re-check still runs at payment submit.
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
                // The write that would otherwise overwrite a newer company's
                // already-rendered result.
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
                // from under a newer request that is still running.
                if (isCurrent()) {
                    this.isProcessing = false;
                }
            });
    }

    /**
     * The company the buyer has selected in this browser, as published by
     * TwoCompanySearch through TwoCheckoutManager (`getConfirmedCompany`,
     * injected in initializeOrderIntent()).
     *
     * TWO-25326 bug 8: the request payload, not the response ordering, was the
     * stale half. In tile mode collectFormData() reads nothing from the
     * address-area DOM and falls through to the `getCompany` round trip, which
     * reads the SESSION COOKIE - written by persistCompanyToCookie()'s
     * fire-and-forget `saveCompany` request issued in the same tick. A cookie
     * written by a response the browser has not received yet is not in the
     * request it has already sent, so `getCompany` answers with the PREVIOUS
     * company and the intent fires, legitimately and in order, for company A
     * while the buyer is looking at company B. No response-sequencing gate can
     * see that, because the stale request IS the current one.
     *
     * The in-memory selection needs no round trip and cannot be stale, so it is
     * authoritative - but only while it still applies to the CURRENT checkout
     * context, hence the same invalidation checks as the cookie path.
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
        // An organisation number captured against one address must not be
        // credit-checked against another. 0 on either side means "unknown",
        // which is not evidence of a switch.
        const capturedAddressId = selection.addressId ? parseInt(selection.addressId, 10) : 0;
        const currentAddressId = this.getCurrentAddressId();
        if (capturedAddressId > 0 && currentAddressId > 0 && capturedAddressId !== currentAddressId) {
            return null;
        }
        // The same country invalidation the cookie path applies
        // (`storedCountryMismatch`). Compared only when BOTH are resolvable -
        // at the payment step in tile mode there is often no country select in
        // the DOM at all.
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
            // fields are only trustworthy when the search control lives there.
            // In tile mode `company` stays visible and typeable BY DESIGN,
            // while `companyid` is the tile's own hidden field elsewhere in the
            // DOM - reading them here would let an uncontrolled field override
            // or mismatch-pair with the tile selection, because the server's
            // resolver (getCompanyDataWithFallbacks()) treats posted form data
            // as HIGHER priority than the session. Leave both empty so the
            // fallback below reaches for the session/cookie value instead.
            if (this.config.companySearchInAddressArea !== false) {
                const companyField = document.querySelector("input[name='company']");
                const companyIdField = document.querySelector("input[name='companyid']");
                company = companyField ? (companyField.value || '') : '';
                companyid = companyIdField ? (companyIdField.value || '') : '';
            }

            let countryChanged = false;
            try { countryChanged = (sessionStorage.getItem('two_country_changed') === '1'); } catch (e) {}
            if (countryChanged) {
                company = '';
                companyid = '';
                try { sessionStorage.removeItem('two_country_changed'); } catch (e) {}
            }

            // TWO-25326 bug 8: prefer the in-memory selection over the session
            // cookie - see getConfirmedCompanySelection() for why the cookie
            // read is systematically one selection behind in tile mode.
            //
            // Positioned AFTER the address-area DOM read and gated on it having
            // produced nothing: where those fields ARE the control (address
            // mode), they carry what the buyer has typed since, which is newer
            // than any earlier confirmed selection and must keep winning.
            const confirmedSelection = countryChanged ? null : this.getConfirmedCompanySelection();
            if ((!company && !companyid) && confirmedSelection) {
                formData.company = confirmedSelection.company;
                formData.companyid = confirmedSelection.companyid;
                // Name and number written TOGETHER from the SAME source, and
                // only while this call is still the current one.
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

            // Only fall back to the cookie when BOTH values are missing: one
            // value present and the other missing must keep the form values,
            // to avoid stale mixed company/companyid pairs.
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
                        // Name and number reassigned TOGETHER from the SAME
                        // source, so the sentence can never pair a fresh name
                        // with a stale number. Gated on isCurrent()
                        // (TWO-25326): this round trip can resolve after a
                        // newer checkOrderIntent() call has started.
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
            // Reassigned together, from the SAME address-area DOM read above.
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
                const iso = selectedOption.getAttribute('data-iso-code') || 
                           selectedOption.getAttribute('data-iso') ||
                           selectedOption.getAttribute('data-country-iso');
                if (iso) return iso.toUpperCase();
                
                const value = countryField.value;
                if (value && value.length === 2 && /^[A-Z]{2}$/i.test(value)) {
                    return value.toUpperCase();
                }
            }
            
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
        // The company search answers when there is one: its read is scoped to
        // its own block, and the two must agree or the stamp it compares against
        // is discarded as an address switch.
        const search = typeof this.config.getCompanySearch === 'function'
            ? this.config.getCompanySearch()
            : null;
        if (search && typeof search.getCurrentAddressId === 'function') {
            return search.getCurrentAddressId();
        }

        // TWO-25503: the form the buyer is EDITING outranks the saved-address
        // radios. On the invoice pass of a billing-differs-from-shipping
        // checkout the delivery side is rendered as a radio selector, so a
        // radio-first read answers with the SHIPPING address while the buyer is
        // filling in their billing one - which stamps a billing-address company
        // selection with the wrong address, and every address-switch guard then
        // throws that selection away. A form with no id yet (a new address)
        // answers 0, which those guards read as "unknown" rather than a switch.
        const editableAddressForm = document.querySelector("input[name='saveAddress']");
        if (editableAddressForm) {
            const form = (typeof editableAddressForm.closest === 'function'
                ? editableAddressForm.closest('form[data-id-address]')
                : null) || document.querySelector('.js-address-form form[data-id-address]');
            const parsed = form ? parseInt(form.getAttribute('data-id-address') || '0', 10) : 0;
            return parsed > 0 ? parsed : 0;
        }

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
            if (!window.twopayment || !window.twopayment.order_intent_url || !window.twopayment.ajax_token) {
                reject(new Error('Two order intent failed: module endpoint unavailable'));
                return;
            }

            // Relayed through the module's own controller so the firewall token
            // that Two may require stays server-side.
            $.ajax({
                url: window.twopayment.order_intent_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax: 1,
                    action: 'orderIntent',
                    token: window.twopayment.ajax_token,
                    payload: JSON.stringify(payload)
                },
                timeout: 15000,
                success: (response) => {
                    if (response && typeof response === 'object') {
                        const isApproved = !!response.approved;
                        resolve({ success: true, approved: isApproved, message: '' , rawResponse: response});
                    } else {
                        reject(new Error('Invalid response from Two'));
                    }
                },
                error: (xhr, status, error) => {
                    // TWO-25206: Two is the only thing that verifies the buyer's
                    // organization number, so its rejection reason has to reach
                    // getErrorMessage(). jQuery's statusText alone ("Bad Request")
                    // collapses every 4xx into the generic decline, so carry
                    // error_code and error_message through and let
                    // COMPANY_NOT_FOUND map to the company-search prompt.
                    reject(new Error(`Two order intent failed: ${TwoOrderIntent.describeApiError(xhr, error)}`));
                }
            });
        });
    }

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
        // Declines keep their message - it drives setupOrderPrevention.
        if (result.approved && approvedSuppressed) {
            result.message = '';
        }
        // TWO-25326 §7.1: the address-area field is not a trustworthy source
        // once search has relocated to the tile - see collectFormData().
        if (this.config.companySearchInAddressArea !== false) {
            const companyField = document.querySelector("input[name='company']");
            const fieldValue = companyField && companyField.value ? companyField.value : null;
            if (!this.lastCompany || fieldValue) {
                // TWO-25326: a field value differing from what lastCompany
                // already held means the buyer retyped the name since that
                // number was captured, so the number goes too rather than risk
                // pairing a fresh name with a stale number.
                if (fieldValue && fieldValue !== this.lastCompany) {
                    this.lastCompanyNumber = null;
                }
                this.lastCompany = fieldValue || this.lastCompany;
            }
        }
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
        const defaultMessage = this.t(
            'invoice_declined',
            'Your invoice with Two cannot be approved at this time. Please select an alternative payment method.'
        );
            
        if (!errorString) {
            return defaultMessage;
        }
        const error = ('' + errorString).toLowerCase();

        // The backend (orderintent.php's no_company/incomplete_company
        // branches) already sends a specific instruction telling the buyer
        // exactly what to fix - pass it through rather than letting the keyword
        // matching below fall through to the generic defaultMessage, which
        // tells them to pick a different payment method instead.
        if (error.includes('select your company') || error.includes('search for your company')) {
            return errorString;
        }

        // TWO-25206: Two rejects the order intent with COMPANY_NOT_FOUND when the
        // organization number does not resolve against the company registry.
        // Matched before the generic keyword branches below, 'not found'
        // included, so the wording cannot drift into a vaguer one.
        if (error.includes('company_not_found') || error.includes('company not found')) {
            return this.t(
                'select_company_to_use_two',
                'To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'
            );
        }

        if (error.includes('invalid phone number') || 
            (error.includes('phone_number') && error.includes('value_error'))) {
            return this.t(
                'invalid_phone_number',
                'The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country.'
            );
        }
        
        if (error.includes('invalid email') || 
            (error.includes('email') && error.includes('value_error'))) {
            return this.t('invalid_email', 'The email address provided is invalid. Please check your email and try again.');
        }
        
        if (error.includes('organization_number') || error.includes('organization number')) {
            return this.t(
                'company_incomplete',
                'Company information is incomplete. Go back to your billing address and select your company from the search results.'
            );
        }
        
        if (error.includes('validation error') || error.includes('value_error')) {
            return this.t(
                'validation_error',
                'Some of the information provided is invalid. Please check your billing address details and try again.'
            );
        }
        
        if (error.includes('invalid')) {
            return this.t(
                'invalid_company',
                'The company information provided is not valid. Go back to your billing address and select your company from the search results.'
            );
        }
        
        if (error.includes('not found') || error.includes('404')) {
            return this.t(
                'company_verify_failed',
                'Company information could not be verified. Go back to your billing address and select your company from the search results.'
            );
        }
        
        return defaultMessage;
    }

    handleError(error) {
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
        // all, and drop any element left over from an earlier decline so a
        // stale message cannot outlive an approval.
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
        // Only template in the company name for a real decision FROM Two's API
        // (processResult() always sets rawResponse). handleError()'s results
        // never set it and already carry a specific, actionable message that
        // a generic "cannot be approved for <company>" would discard.
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
        // updateUI() keeps in the Two payment option, present on every PS
        // theme - unlike window.prestashop.notification, a PS9 Bootstrap-5 API
        // absent on PS 8's classic theme.
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
                let countryChanged = false;
                try { countryChanged = (sessionStorage.getItem('two_country_changed') === '1'); } catch (e) {}
                
                if (countryChanged) {
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
        // TWO-25326: invalidate any request already in flight, independent of
        // whether a new checkOrderIntent() call follows. A caller that resets
        // but does not immediately re-check (blocked by
        // triggerOrderIntentForSelection()'s cooldown) would otherwise leave
        // the old request "current", to land and overwrite the cleared state.
        this.requestSeq += 1;
        this.stopMonitoring();
    }
}

window.TwoOrderIntent = TwoOrderIntent;
