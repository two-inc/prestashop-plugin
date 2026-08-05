/**
 * Two Sole Trader - presentation layer for the sole-trader checkout flow
 * (TWO-24755).
 *
 * Renders a Business / Sole trader toggle on the payment step - the same
 * model as the Magento and WooCommerce plugins - shown wherever Two's
 * registry says the billing country supports sole traders. That country
 * answer is the only gate; there is no merchant opt-in toggle
 * (TWO-25166). There is no account-type selector on
 * the address form; the buyer always enters company details (B2B), and
 * sole traders enrol from this toggle.
 *
 * Picking "Sole trader" mints the delegation + autofill tokens
 * server-side, opens Two's hosted signup popup, and autofills the
 * company fields from GET /autofill/v1/buyer/current on completion. An
 * enrolled sole trader then checks out as a regular business - the
 * synthetic organization number their registration minted carries the
 * semantics, so the order payload is unchanged.
 *
 * All decisioning (country eligibility, token minting) lives server-side
 * in classes/TwoSoleTrader.php; this module only renders the result.
 */
class TwoSoleTrader {
    constructor(config) {
        this.config = {
            checkoutHost: '',
            orderIntentUrl: '',
            ajaxToken: '',
            shopCountry: '',
            i18n: {},
            ...config
        };
        this.mode = 'business';
        this.tokens = null;
        this.flowStarted = false;
        this.messageListenerBound = false;
        // Server-resolved availability, cached per billing country for the
        // page's lifetime so the toggle settles without re-fetching.
        this.availabilityByCountry = {};
        this.renderedForCountry = null;
        // TWO-25326 bug 9: the container node this instance last rendered into.
        // `renderedForCountry` alone is not a record of what is on the page:
        // PrestaShop REPLACES the payment fragment (and with it this whole
        // container) while the checkout step settles, and the replacement
        // arrives with no chips built and none of the inline display state
        // render()/hide() set. Keyed on the node, the settled-check can tell
        // "already rendered" from "rendered into a node that no longer exists".
        this.renderedContainer = null;
        this.observer = null;
        // TWO-25326 bug 9: the country an availability request is currently out
        // for, and the debounce handle for the MutationObserver. See init() and
        // refreshAvailability() for what each prevents.
        this.pendingCountry = null;
        this._refreshTimeoutId = null;
        // In-flight guard + cooldown: setMode('sole_trader') re-invokes
        // fetchTokens() whenever tokens aren't set yet, so repeated clicks
        // on the "Sole trader" chip while a mint keeps failing (network
        // blip, no invoice address yet) would otherwise re-issue the mint
        // - two upstream Two API calls - on every click, with no backoff.
        // (The MutationObserver only calls the cheap, self-caching
        // refreshAvailability(), not fetchTokens(), so it is not the
        // threat this guards against.)
        this.isFetchingTokens = false;
        this.nextRetryAt = 0;
        this.retryCooldownMs = 5000;

        this.init();
    }

    init() {
        const self = this;
        // The payment box and the billing country can both re-render
        // across checkout step transitions; re-evaluate availability on
        // each change.
        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches("select[name='id_country'], select[name='country']")) {
                self.refreshAvailability();
            }
        });
        // DEBOUNCED (TWO-25326 bug 9). This observer watches the whole body
        // subtree, and refreshAvailability() itself MUTATES that subtree
        // (render() appends chips and sets inline display) - so every render fed
        // the observer, which called back synchronously, once per mutation
        // record. Coalescing a burst into one call is what makes the
        // render -> observe -> render path terminate on the first pass instead
        // of being throttled only by the settled-checks downstream of it.
        this.observer = new MutationObserver(function () {
            self.scheduleRefresh();
        });
        this.observer.observe(document.body, { childList: true, subtree: true });
        this.refreshAvailability();
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
     */
    stopObserving() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
        clearTimeout(this._refreshTimeoutId);
        this._refreshTimeoutId = null;
    }

    container() {
        return document.querySelector('.two-sole-trader');
    }

    text(key, fallback) {
        return (this.config.i18n && this.config.i18n[key]) || fallback;
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
        return (this.config.shopCountry || '').toUpperCase();
    }

    /**
     * Decide whether to show the toggle for the current billing country.
     * Availability (registry endpoint + merchant toggle) is resolved
     * server-side; fail-soft to "not available" so checkout never blocks.
     */
    refreshAvailability() {
        const container = this.container();
        if (!container) {
            return;
        }
        const country = this.billingCountry();
        if (!country) {
            this.hide();
            return;
        }
        // Settled means "this container, for this country" - not the country
        // alone (TWO-25326 bug 9). A country-only check reported the toggle as
        // settled after PrestaShop had replaced the container out from under it,
        // so the chips stayed missing from a container this instance believed it
        // had already rendered into, until some unrelated later trigger happened
        // to re-render them - which is the render / disappear / reappear cycle
        // Doug saw on the chips specifically, distinct from the tile-level
        // mount/unmount guard.
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
        // storm, it made the toggle's visibility a race between those
        // responses: this endpoint is fail-soft to "not available", so one
        // failing or timing out among a dozen in-flight duplicates called
        // hide() while its siblings called render(), flickering the chips in and
        // out with no state change behind it at all.
        if (this.pendingCountry === country) {
            return;
        }
        this.pendingCountry = country;
        const self = this;
        fetch(this.moduleUrl('soleTraderAvailability') + '&country=' + encodeURIComponent(country), { method: 'GET' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                const available = !!(json && json.success && json.available);
                self.availabilityByCountry[country] = available;
                self.releasePending(country);
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
     * Is what this instance last rendered still actually on the page, in the
     * container it is being asked about?
     *
     * Both halves matter. The node must be the one rendered into (PrestaShop
     * replaces it wholesale), and the chips must still be built inside it -
     * `render()` marks the toggle it built with `data-two-built`, and a
     * replacement fragment arrives without that marker.
     *
     * @param {HTMLElement} container
     * @returns {boolean}
     */
    isSettledFor(container) {
        if (!container || container !== this.renderedContainer) {
            return false;
        }
        if (!container.isConnected) {
            return false;
        }
        // Only the available/rendered case builds chips; when the answer was
        // "not available" there is deliberately nothing to build, and hide()'s
        // inline display is the state to preserve.
        if (this.availabilityByCountry[this.renderedForCountry] !== true) {
            return true;
        }
        const toggle = container.querySelector('.two-sole-trader__toggle');
        return !!(toggle && toggle.dataset.twoBuilt === '1');
    }

    apply(country, available) {
        this.renderedForCountry = country;
        // Recorded BEFORE render()/hide() run, so the mutations they make -
        // which the observer sees - already find the state settled and stop
        // (TWO-25326 bug 9).
        this.renderedContainer = this.container();
        if (available) {
            this.render();
        } else {
            this.hide();
        }
    }

    hide() {
        const container = this.container();
        if (container) {
            container.style.display = 'none';
        }
        if (this.mode === 'sole_trader') {
            this.setMode('business');
        }
    }

    /**
     * Render the Business / Sole trader toggle into the payment-step
     * container. Chips are built once; subsequent calls just reveal it.
     */
    render() {
        const container = this.container();
        if (!container) {
            return;
        }
        container.style.display = 'block';
        const toggle = container.querySelector('.two-sole-trader__toggle');
        if (toggle && toggle.dataset.twoBuilt !== '1') {
            const self = this;
            [
                { value: 'business', label: this.text('registered_business', 'Registered business') },
                { value: 'sole_trader', label: this.text('sole_trader', 'Sole trader') }
            ].forEach(function (option) {
                const chip = document.createElement('span');
                chip.className = 'two-sole-trader__mode';
                chip.setAttribute('role', 'button');
                chip.setAttribute('tabindex', '0');
                chip.dataset.mode = option.value;
                chip.textContent = option.label;
                chip.addEventListener('click', function (event) {
                    event.preventDefault();
                    self.setMode(option.value);
                });
                chip.addEventListener('keypress', function (event) {
                    if (event.which === 13 || event.which === 32) {
                        event.preventDefault();
                        self.setMode(option.value);
                    }
                });
                toggle.appendChild(chip);
            });
            toggle.dataset.twoBuilt = '1';
        }
        this.updateChips();
    }

    updateChips() {
        const container = this.container();
        if (!container) {
            return;
        }
        const self = this;
        container.querySelectorAll('.two-sole-trader__mode').forEach(function (chip) {
            chip.classList.toggle('two-sole-trader__mode--selected', chip.dataset.mode === self.mode);
        });
    }

    /**
     * Switch mode. Sole trader mints tokens then autofills (or prompts
     * the signup popup) once per page; business just hides the prompt.
     * The popup opens from the prompt link's own click so the blocker
     * lets it through.
     */
    setMode(mode) {
        this.mode = mode;
        this.updateChips();
        if (mode === 'sole_trader') {
            if (!this.flowStarted || !this.tokens) {
                this.flowStarted = true;
                this.fetchTokens();
            } else if (this.tokens) {
                this.getCurrentBuyer();
            }
        } else {
            this.hidePrompt();
        }
    }

    /**
     * Mint tokens, guarded against a request storm: refuses re-entry
     * while a request is already outstanding (isFetchingTokens) and
     * enforces a minimum gap between attempts after a failure
     * (nextRetryAt/retryCooldownMs) - repeated clicks on the toggle chip
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
                    self.showStatus(window.TwoCompanyNumber.forDisplay(companyLabel) || self.text('sole_trader', 'Sole trader'));
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
            if (self.mode !== 'sole_trader' || !self.tokens) {
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
