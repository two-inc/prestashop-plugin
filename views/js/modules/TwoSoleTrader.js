/**
 * Two Sole Trader - enrolment mechanics for the sole-trader checkout flow
 * (TWO-24755).
 *
 * TWO-40 removed the upfront Business / Sole trader chip choice this module
 * used to render on the payment step. There is now a single entry point
 * regardless of business type: the company search control
 * (TwoCompanySearch.js). When the registry says the billing country
 * supports sole traders, that control shows an "I'm a sole trader" row
 * alongside "My company is not on the list" and calls
 * `startEnrollment()`/`isAvailableForCurrentCountry()` below directly - there
 * is no chip, and no toggle markup, in between.
 *
 * This module still owns everything about what happens once enrolment
 * starts: minting the delegation + autofill tokens server-side, opening
 * Two's hosted signup popup, and autofilling the company fields from
 * GET /autofill/v1/buyer/current on completion. An enrolled sole trader
 * then checks out as a regular business - the synthetic organization
 * number their registration minted carries the semantics, so the order
 * payload is unchanged.
 *
 * All decisioning (country eligibility, token minting) lives server-side
 * in classes/TwoSoleTrader.php.
 *
 * The availability ANSWER is still handed over server-side, as of TWO-25326
 * bug 9 round 3: paymentinfo.tpl renders `data-two-available`/
 * `data-two-country` on `.two-sole-trader` from the registry answer, and
 * adoptServerRenderedToggle() below takes that over as this instance's
 * settled availability cache - so the search control's "I'm a sole trader"
 * row can appear on first paint with no round trip of its own. That
 * container renders no visible chips or toggle any more; it now exists only
 * to host the prompt/status/error messaging shown once enrolment starts.
 */
class TwoSoleTrader {
    constructor(config) {
        this.config = {
            checkoutHost: '',
            orderIntentUrl: '',
            ajaxToken: '',
            // The country of the cart's OWN billing address, resolved server-side
            // (TWO-25326 bug 9, round 3 adversarial review). See billingCountry().
            billingCountry: '',
            shopCountry: '',
            ...config
        };
        // Whether an enrolment (signup popup + autofill) is currently under
        // way. Replaces the old chip's 'business'/'sole_trader' mode - there
        // is no second mode to switch back to any more, just "not enrolling"
        // and "enrolling".
        this.enrolling = false;
        this.tokens = null;
        this.flowStarted = false;
        this.messageListenerBound = false;
        // Server-resolved availability, cached per billing country for the
        // page's lifetime so isAvailableForCurrentCountry() settles without
        // re-fetching.
        this.availabilityByCountry = {};
        this.renderedForCountry = null;
        // TWO-25326 bug 9: the container node this instance last adopted an
        // answer into. `renderedForCountry` alone is not a record of what is
        // on the page: PrestaShop REPLACES the payment fragment (and with it
        // this whole container) while the checkout step settles, and the
        // replacement arrives with no adopted answer at all. Keyed on the
        // node, the settled-check can tell "already adopted" from "adopted
        // into a node that no longer exists".
        this.renderedContainer = null;
        this.observer = null;
        // The country-change subscription, held so destroy() can detach it.
        // stopObserving() deliberately does NOT - see both methods.
        this._countryChangeHandler = null;
        // Bumped every time a SERVER-rendered answer is adopted (TWO-25326 bug 9,
        // round 3 review, finding 1). An availability request captures it before
        // it starts and drops its own result if it has moved: the server's answer
        // arrived later than the request did, so the request is stale however
        // in-order it looked when it was issued. Without this, a request already
        // in flight when PrestaShop replaced the fragment could overwrite the
        // answer that replacement carried - and because a failed request applies
        // "not available" while availabilityByCountry still held the adopted
        // `true`, the settled-check then agreed the cache was correct and
        // isAvailableForCurrentCountry() stayed wrong for the rest of the
        // page's life.
        this._adoptGeneration = 0;
        // TWO-25326 bug 9: the country an availability request is currently out
        // for, and the debounce handle for the MutationObserver. See init() and
        // refreshAvailability() for what each prevents.
        this.pendingCountry = null;
        this._refreshTimeoutId = null;
        // In-flight guard + cooldown: startEnrollment() re-invokes
        // fetchTokens() whenever tokens aren't set yet, so repeated clicks
        // on the "I'm a sole trader" row while a mint keeps failing (network
        // blip, no invoice address yet) would otherwise re-issue the mint
        // - two upstream Two API calls - on every click, with no backoff.
        // (The MutationObserver only calls the cheap, self-caching
        // refreshAvailability(), not fetchTokens(), so it is not the
        // threat this guards against.)
        this.isFetchingTokens = false;
        this.nextRetryAt = 0;
        this.retryCooldownMs = 5000;

        // TWO-25326 bug 9, round 3: take the availability answer the SERVER
        // already resolved as this instance's settled state, before init()
        // can decide to fetch anything.
        this.adoptServerRenderedToggle();

        this.init();
    }

    /**
     * Adopt the server-rendered availability answer as this instance's
     * settled state.
     *
     * WHY THIS EXISTS (TWO-25326 bug 9, round 3; TWO-40 removed the chip UI it
     * used to feed). The answer used to be resolved ONLY here, in the browser,
     * behind an availability round trip - so on every single load of the
     * payment step there was no answer at all until that request came back.
     * Measured on the staging shop: ~280ms of "no answer" on every load. The
     * only fix is for the answer to already be in the markup, so
     * paymentinfo.tpl renders `.two-sole-trader`'s data- attributes from the
     * server-side registry answer (Twopayment::getTwoPaymentOption ->
     * TwoSoleTrader::isAvailable, the same source this module's endpoint uses)
     * and this method takes it over as-is - which is what lets
     * isAvailableForCurrentCountry() answer correctly for TwoCompanySearch.js's
     * "I'm a sole trader" row on first paint, with no round trip of its own.
     *
     * Strict about what counts as a server answer: `data-two-available` must be
     * exactly "1" or "0" and `data-two-country` must be an ISO-2 code. Anything
     * else - an older cached template, a theme that rebuilt the markup - reads as
     * "no answer" and leaves the client fetch as the only path, i.e. exactly the
     * previous behaviour.
     *
     * @returns {void}
     */
    adoptServerRenderedToggle() {
        const container = this.container();
        if (!container) {
            return;
        }
        const answer = container.getAttribute('data-two-available');
        if (answer !== '1' && answer !== '0') {
            return;
        }
        const country = (container.getAttribute('data-two-country') || '').toUpperCase();
        if (!/^[A-Z]{2}$/.test(country)) {
            return;
        }
        this.availabilityByCountry[country] = (answer === '1');
        this.renderedForCountry = country;
        this.renderedContainer = container;
        this._adoptGeneration += 1;
    }

    init() {
        const self = this;
        // The payment box and the billing country can both re-render
        // across checkout step transitions; re-evaluate availability on
        // each change.
        // Kept on the instance so destroy() can detach it (TWO-25326 bug 9,
        // round 3). It used to be an anonymous closure with no reference kept, so
        // there was no way to detach it at all. stopObserving() deliberately does
        // NOT detach it - see both methods for why the two are separate.
        this._countryChangeHandler = function (event) {
            if (event.target && event.target.matches("select[name='id_country'], select[name='country']")) {
                self.refreshAvailability();
            }
        };
        document.addEventListener('change', this._countryChangeHandler);
        // DEBOUNCED (TWO-25326 bug 9). This observer watches the whole body
        // subtree, and PrestaShop's own address-form/payment-fragment
        // re-renders mutate that subtree constantly - so every one of those
        // fed the observer, which called back synchronously, once per mutation
        // record. Coalescing a burst into one call is what keeps re-adoption
        // (below) cheap instead of running once per mutation record.
        this.observer = new MutationObserver(function () {
            // Container-identity check runs UNDEBOUNCED, the availability refresh
            // does not (TWO-25326 bug 9, round 3 adversarial review). A replaced
            // fragment carries its OWN server-rendered availability answer, which
            // this instance has not adopted yet - so waiting out the 100ms
            // debounce left the availability cache answering for a node that no
            // longer exists. Re-adopting is pure DOM work with no request in it. Once a
            // container IS adopted the check is a no-op however many mutations
            // follow; when the replacement carries no parseable answer nothing is
            // adopted, so the check keeps re-running - one querySelector and two
            // attribute reads per observer batch, which is the price of the
            // fallback path rather than a loop.
            self.adoptReplacedContainer();
            self.scheduleRefresh();
        });
        this.observer.observe(document.body, { childList: true, subtree: true });
        this.refreshAvailability();
    }

    /**
     * Re-adopt when PrestaShop has swapped the `.two-sole-trader` container
     * for a fresh one.
     *
     * The replacement carries a FRESHER answer than this instance's cache
     * (TWO-25326 bug 9, round 3 adversarial review). A container rendered
     * `data-two-available="0"` while the cache still said true for the same
     * country used to be treated as available, i.e. the stale client answer
     * overwrote the current server one.
     *
     * @returns {void}
     */
    adoptReplacedContainer() {
        const container = this.container();
        if (container && container !== this.renderedContainer) {
            this.adoptServerRenderedToggle();
        }
    }

    /**
     * Coalesce a burst of DOM mutations into a single availability refresh.
     *
     * @returns {void}
     */
    scheduleRefresh() {
        const self = this;
        clearTimeout(this._refreshTimeoutId);
        this._refreshTimeoutId = setTimeout(function () {
            self.refreshAvailability();
        }, 100);
    }

    /**
     * Once the flow has resolved (enrolled company saved) there is
     * nothing left for this instance to react to; stop observing rather
     * than running a body-wide observer for the rest of checkout.
     *
     * Deliberately leaves the country-change listener attached - see destroy().
     * An enrolled buyer who then changes to a business-only country must still
     * have any pending enrolment cancelled, and refreshAvailability() -> apply()
     * is what does that.
     */
    stopObserving() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
        clearTimeout(this._refreshTimeoutId);
        this._refreshTimeoutId = null;
    }

    /**
     * Detach EVERY page-level subscription this instance owns.
     *
     * Separate from stopObserving() on purpose (TWO-25326 bug 9, round 3
     * adversarial review). stopObserving() means "this flow is resolved", which is
     * NOT the same as "this instance is gone" - a resolved instance must still
     * react to a country change (see above), and folding the two together
     * silently dropped that. This is the "gone" one: nothing left bound, for a
     * teardown or a test that must not leave a second writer behind.
     *
     * The country-change handler is held on the instance for exactly this reason;
     * it used to be an anonymous closure with no reference kept, so there was no
     * way to detach it at all.
     *
     * @returns {void}
     */
    destroy() {
        this.stopObserving();
        if (this._countryChangeHandler) {
            document.removeEventListener('change', this._countryChangeHandler);
            this._countryChangeHandler = null;
        }
    }

    container() {
        return document.querySelector('.two-sole-trader');
    }

    moduleUrl(action) {
        const url = new URL(this.config.orderIntentUrl, window.location.origin);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('action', action);
        url.searchParams.set('token', this.config.ajaxToken);
        return url.toString();
    }

    billingCountry() {
        const field = document.querySelector("select[name='id_country'], select[name='country']");
        if (field && field.selectedOptions && field.selectedOptions.length) {
            const option = field.selectedOptions[0];
            // Same attribute-name fallback chain as TwoOrderIntent.js/
            // TwoCompanySearch.js - themes vary in which one they render.
            const iso = option.getAttribute('data-iso-code')
                || option.getAttribute('data-iso')
                || option.getAttribute('data-country-iso');
            if (iso) {
                return iso.toUpperCase();
            }
        }
        // The cart's billing country BEFORE the shop's own country (TWO-25326 bug
        // 9, round 3 adversarial review). PrestaShop only renders the address FORM
        // - and therefore the select read above - while the buyer is editing an
        // address; on the payment step there is no select at all. So this fallback
        // is what the payment step actually uses, and `shopCountry` is the wrong
        // answer there: it is the visitor/shop country, not the country the order
        // will be billed to. On any cart where the two differ that silently
        // re-resolved availability for the WRONG country and applied it over the
        // server-rendered answer - reintroducing the very flicker the server
        // render exists to remove, with a wrong-country answer on the end of it.
        // Same value the server rendered the toggle from, so they agree by
        // construction; shopCountry stays as a last resort for a payload that
        // predates this key.
        return (this.config.billingCountry || this.config.shopCountry || '').toUpperCase();
    }

    /**
     * Resolve availability for the current billing country and cache it.
     * Availability is resolved server-side; fail-soft to "not available" so
     * checkout never blocks.
     */
    refreshAvailability() {
        const container = this.container();
        if (!container) {
            return;
        }
        // Also here, not only in the observer callback: this method is reached
        // from the country-change listener too, which can fire after a fragment
        // replacement the observer's debounce has not drained yet. Idempotent.
        this.adoptReplacedContainer();
        const country = this.billingCountry();
        if (!country) {
            this.apply('', false);
            return;
        }
        // Settled means "this container, for this country" - not the country
        // alone (TWO-25326 bug 9). A country-only check reported the answer as
        // settled after PrestaShop had replaced the container out from under it,
        // until some unrelated later trigger happened to re-adopt it - which is
        // the render / disappear / reappear cycle Doug saw on the old chips,
        // distinct from the tile-level mount/unmount guard.
        if (country === this.renderedForCountry && this.isSettledFor(container)) {
            return;
        }
        if (country in this.availabilityByCountry) {
            this.apply(country, this.availabilityByCountry[country]);
            return;
        }
        // One request in flight per country (TWO-25326 bug 9). The observer
        // above fires while the answer for the first-ever country is still
        // outstanding - `renderedForCountry` is null and nothing is cached yet,
        // so EVERY firing used to start another `fetch`. Beyond being a request
        // storm, it made the cached answer a race between those responses: this
        // endpoint is fail-soft to "not available", so one failing or timing out
        // among a dozen in-flight duplicates could overwrite an answer another
        // had just applied, with no real state change behind it at all.
        if (this.pendingCountry === country) {
            return;
        }
        this.pendingCountry = country;
        const self = this;
        // Captured BEFORE the request starts (TWO-25326 bug 9, round 3 review,
        // finding 1). If a server-rendered answer is adopted while this is in
        // flight - which happens whenever PrestaShop replaces the payment
        // fragment, the thing this module is built around - then the server has
        // answered more recently than this request was even issued, and this
        // result must be discarded rather than applied over it.
        const generation = this._adoptGeneration;
        const superseded = function () {
            if (self._adoptGeneration === generation) {
                return false;
            }
            // Re-ask for whatever the country is NOW before dropping this result
            // (round 4 review, finding 1). The counter is per instance, not per
            // country, so a replacement carrying an answer for country A
            // supersedes an outstanding request for country B - and simply
            // discarding it left nothing resolved for B and nothing scheduled to
            // resolve it: pendingCountry had cleared, and the debounced refresh
            // that would have re-asked had already run and bailed while the
            // request was still out. A lost wakeup, and a state the previous code
            // could not reach because it applied the stale answer instead.
            // Cheap and self-limiting: refreshAvailability() returns immediately
            // when the country it finds is already settled.
            self.scheduleRefresh();

            return true;
        };
        fetch(this.moduleUrl('soleTraderAvailability') + '&country=' + encodeURIComponent(country), { method: 'GET' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                self.releasePending(country);
                if (superseded()) {
                    return;
                }
                const available = !!(json && json.success && json.available);
                self.availabilityByCountry[country] = available;
                // The buyer may have changed country mid-request; only
                // apply if the answer is still for the current one.
                if (self.billingCountry() === country) {
                    self.apply(country, available);
                }
            })
            .catch(function () {
                // NOT cached: a transport failure is not an answer about this
                // country, and caching it would make one blip permanent for the
                // rest of the page's life. Only the pending marker clears, so a
                // later mutation or country change can ask again.
                self.releasePending(country);
                if (superseded()) {
                    return;
                }
                if (self.billingCountry() === country) {
                    self.apply(country, false);
                }
            });
    }

    /**
     * Clear the in-flight marker, but only if it is still this country's - the
     * buyer may have changed country and started a newer request.
     *
     * @param {string} country
     * @returns {void}
     */
    releasePending(country) {
        if (this.pendingCountry === country) {
            this.pendingCountry = null;
        }
    }

    /**
     * Is the availability answer this instance last adopted still current for
     * the container it is being asked about?
     *
     * There is no chip markup to verify building/binding of any more (TWO-40)
     * - the only thing that can go stale is the container node itself, which
     * PrestaShop replaces wholesale on a fragment re-render.
     *
     * @param {HTMLElement} container
     * @returns {boolean}
     */
    isSettledFor(container) {
        return !!(container && container === this.renderedContainer && container.isConnected);
    }

    apply(country, available) {
        this.renderedForCountry = country;
        this.renderedContainer = this.container();
        // An enrolment in progress for a country that has just stopped being
        // eligible (buyer changed country mid-flow) must not keep showing a
        // prompt/status for it - mirrors the old chip's hide()-forces-business
        // behaviour, without a "business" mode to fall back to.
        if (!available && this.enrolling) {
            this.cancelEnrollment();
        }
    }

    /**
     * @returns {boolean} whether the registry says the CURRENT billing
     *   country supports sole traders. Read by TwoCompanySearch.js to decide
     *   whether to show its "I'm a sole trader" row - the single place that
     *   decision is made now that there is no upfront chip (TWO-40).
     */
    isAvailableForCurrentCountry() {
        const country = this.billingCountry();
        if (!country) {
            return false;
        }
        return this.availabilityByCountry[country] === true;
    }

    /**
     * Start (or resume) sole-trader enrolment: mint tokens then autofill (or
     * prompt the signup popup) once per page. Called directly by
     * TwoCompanySearch.js's "I'm a sole trader" row - there is no chip in
     * between any more (TWO-40).
     */
    startEnrollment() {
        this.enrolling = true;
        if (!this.flowStarted || !this.tokens) {
            this.flowStarted = true;
            this.fetchTokens();
        } else if (this.tokens) {
            this.getCurrentBuyer();
        }
    }

    /**
     * Abandon an in-progress enrolment without discarding minted tokens -
     * re-entering via startEnrollment() resumes rather than re-mints. Called
     * when the buyer goes back to ordinary company search (opening the
     * dropdown again) or when the billing country stops being eligible.
     */
    cancelEnrollment() {
        this.enrolling = false;
        this.hidePrompt();
    }

    /**
     * Mint tokens, guarded against a request storm: refuses re-entry
     * while a request is already outstanding (isFetchingTokens) and
     * enforces a minimum gap between attempts after a failure
     * (nextRetryAt/retryCooldownMs) - repeated clicks on "I'm a sole trader"
     * while the flow is broken could otherwise re-invoke this on every
     * click.
     */
    fetchTokens() {
        if (this.isFetchingTokens || Date.now() < this.nextRetryAt) {
            return;
        }
        this.isFetchingTokens = true;
        const self = this;
        fetch(this.moduleUrl('soleTraderTokens'), { method: 'POST' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json && json.success && json.autofill_token) {
                    self.tokens = json;
                    self.bindPopupMessageListener();
                    self.getCurrentBuyer();
                } else {
                    self.tokens = null;
                    self.nextRetryAt = Date.now() + self.retryCooldownMs;
                    self.showError();
                }
            })
            .catch(function () {
                self.tokens = null;
                self.nextRetryAt = Date.now() + self.retryCooldownMs;
                self.showError();
            })
            .finally(function () {
                self.isFetchingTokens = false;
            });
    }

    /**
     * The buyer's email as entered in the checkout, for matching against
     * the Two registration. Sourced from PrestaShop's customer object
     * (present once the personal-information step is done) with the
     * email form field as fallback.
     */
    checkoutEmail() {
        const ps = window.prestashop;
        if (ps && ps.customer && ps.customer.email) {
            return String(ps.customer.email);
        }
        const field = document.querySelector("input[name='email'], #email");
        return field ? String(field.value || '') : '';
    }

    /**
     * Autofill from the buyer's current Two sole-trader business. A 404,
     * a missing checkout email, or an email mismatch means no usable
     * registration yet - show the signup prompt instead. The email match
     * is case-insensitive and required.
     */
    getCurrentBuyer() {
        const self = this;
        fetch(this.config.checkoutHost + '/autofill/v1/buyer/current', {
            credentials: 'include',
            headers: { 'two-delegated-authority-token': this.tokens.autofill_token }
        })
            .then(function (response) {
                if (response.ok) {
                    return response.json();
                }
                if (response.status === 404) {
                    return null;
                }
                throw new Error('autofill/v1/buyer/current failed');
            })
            .then(function (buyer) {
                const entered = self.checkoutEmail().trim().toLowerCase();
                const matches = !!(buyer && buyer.email && entered
                    && String(buyer.email).toLowerCase() === entered);
                if (matches) {
                    self.applyBuyer(buyer);
                } else {
                    self.showPrompt();
                }
            })
            .catch(function () {
                self.showError();
            });
    }

    /**
     * Persist the enrolled sole trader's company data through the
     * existing saveCompany cookie action; the order-intent and payment
     * paths then pick it up via the regular company-data fallback chain.
     *
     * Two things a naive implementation gets wrong here:
     *  - company_name may be BLANK for a sole trader (they often trade
     *    under their own name, not a company name); saveCompany rejects
     *    an empty company, so fall back to the organization number - the
     *    org-number prefix is what carries the sole-trader semantics
     *    server-side anyway (TWO-24749).
     *  - the country MUST be the token response's server-resolved invoice
     *    country, not a DOM guess: getTwoValidatedSessionCompanyData()
     *    wipes the whole session company the moment the saved country
     *    disagrees with the cart's actual invoice-address country.
     */
    applyBuyer(buyer) {
        const self = this;
        const companyLabel = buyer.company_name || buyer.organization_number || '';
        const body = new URLSearchParams({
            company: companyLabel,
            companyid: buyer.organization_number || '',
            country: (this.tokens && this.tokens.country) || ''
        });
        fetch(this.moduleUrl('saveCompany'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json && json.success) {
                    // TWO-25326 bug 8, review round 1: publish the enrolled
                    // sole trader as the confirmed selection, exactly as a
                    // search selection does.
                    //
                    // Required, not tidying. The order-intent check now
                    // prefers the browser's in-memory selection over the
                    // session cookie this request just wrote - so a buyer who
                    // picked a registered company FIRST and then enrolled as a
                    // sole trader would have the check keep posting that
                    // earlier company, and the endpoint re-stores whatever it
                    // is posted into the session - overwriting the sole-trader
                    // record the ORDER payload reads. Publishing here keeps
                    // the in-memory copy and the cookie agreeing on which
                    // entity the buyer is.
                    self.publishConfirmedSelection(companyLabel, buyer.organization_number || '');
                    // TWO-25326 §12, review round 2: companyLabel falls back to
                    // buyer.organization_number when company_name is blank
                    // (see the comment above applyBuyer) - and that is exactly
                    // where the synthetic `TWO:`-prefixed identifier shows up,
                    // since it exists to stand in for a buyer with no name or
                    // number of their own. The persisted `company` field above
                    // still carries it (server semantics depend on it, per the
                    // comment above); only this on-screen status must not.
                    self.showStatus(window.TwoCompanyNumber.forDisplay(companyLabel) || 'Sole trader');
                    self.hidePrompt();
                    self.stopObserving();
                    document.dispatchEvent(new CustomEvent('two:sole-trader-ready'));
                } else {
                    self.showError();
                }
            })
            .catch(function () {
                self.showError();
            });
    }

    /**
     * Publish a confirmed company/organisation-number pair to
     * TwoCheckoutManager, which is what the order-intent payload is built from
     * (TWO-25326 bug 8). Mirror of TwoCompanySearch.publishConfirmedSelection()
     * - the two modules are the only two places a company is captured, and they
     * must feed the same store or the intent check can be built for the entity
     * the buyer is NOT.
     *
     * @param {string} company
     * @param {string} companyid
     * @returns {void}
     */
    publishConfirmedSelection(company, companyid) {
        try {
            const manager = window.TwoCheckoutManager_Instance;
            if (!manager || typeof manager.setConfirmedCompanySelection !== 'function') {
                return;
            }
            manager.setConfirmedCompanySelection({ company: company, companyid: companyid });
        } catch (e) {
            // no-op: presentation only, never a gate.
        }
    }

    /**
     * Base64 for the signup page's autofillData parameter. UTF-8-safe:
     * a bare btoa() throws on any character outside Latin-1 (e.g. å/ø/æ
     * in names) - matches the WooCommerce plugin's encoding. Magento's
     * current code uses a bare btoa() there (verified against its real
     * source), which is a latent gap in Magento rather than a contract
     * worth replicating, so this deliberately does not match it.
     */
    encodeAutofillData(data) {
        return btoa(unescape(encodeURIComponent(JSON.stringify(data))));
    }

    openPopup() {
        if (!this.tokens) {
            return null;
        }
        const ps = window.prestashop;
        const customer = (ps && ps.customer) || {};
        const firstName = document.querySelector("input[name='firstname']");
        const lastName = document.querySelector("input[name='lastname']");
        const phone = document.querySelector("input[name='phone']");
        const prefill = {
            email: this.checkoutEmail(),
            first_name: (firstName && firstName.value) || customer.firstname || '',
            last_name: (lastName && lastName.value) || customer.lastname || '',
            phone_number: phone ? phone.value : ''
        };
        const url =
            this.tokens.signup_url +
            '?businessToken=' + encodeURIComponent(this.tokens.delegation_token) +
            '&autofillToken=' + encodeURIComponent(this.tokens.autofill_token) +
            '&autofillData=' + encodeURIComponent(this.encodeAutofillData(prefill));
        const popup = window.open(
            url,
            '_blank',
            'location=yes,resizable=yes,scrollbars=yes,status=yes,height=805,width=610'
        );
        if (!popup) {
            // Popup blocked despite opening from a click - surface it
            // rather than failing silently.
            this.showError();
        }
        return popup;
    }

    /**
     * The hosted signup posts 'ACCEPTED' back to the opener when the
     * buyer completes registration; re-fetch the buyer to autofill.
     * Origin must be the signup page's own. Any other message from that
     * origin is ignored rather than treated as a failure - the signup
     * page may emit unrelated messages (resize/analytics) that are none
     * of our business.
     */
    bindPopupMessageListener() {
        if (this.messageListenerBound) {
            return;
        }
        this.messageListenerBound = true;
        const self = this;
        window.addEventListener('message', function (event) {
            if (!self.enrolling || !self.tokens) {
                return;
            }
            if (event.origin !== new URL(self.tokens.signup_url).origin) {
                return;
            }
            if (event.data === 'ACCEPTED') {
                self.getCurrentBuyer();
            }
        });
    }

    showPrompt() {
        const self = this;
        const prompt = this.container() && this.container().querySelector('.two-sole-trader__prompt');
        if (!prompt) {
            return;
        }
        prompt.style.display = 'inline';
        if (!prompt.dataset.twoBound) {
            prompt.dataset.twoBound = '1';
            prompt.addEventListener('click', function (event) {
                event.preventDefault();
                self.openPopup();
            });
        }
    }

    hidePrompt() {
        const prompt = this.container() && this.container().querySelector('.two-sole-trader__prompt');
        if (prompt) {
            prompt.style.display = 'none';
        }
        const status = this.container() && this.container().querySelector('.two-sole-trader__status');
        if (status) {
            status.style.display = 'none';
        }
    }

    showStatus(label) {
        const status = this.container() && this.container().querySelector('.two-sole-trader__status');
        if (status) {
            status.textContent = label;
            status.style.display = 'inline';
        }
    }

    showError() {
        const error = this.container() && this.container().querySelector('.two-sole-trader__error');
        if (error) {
            error.style.display = 'inline';
        }
    }
}

window.TwoSoleTrader = TwoSoleTrader;
