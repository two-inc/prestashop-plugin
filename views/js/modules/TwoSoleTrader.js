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
        this.observer = null;
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
        this.observer = new MutationObserver(function () {
            self.refreshAvailability();
        });
        this.observer.observe(document.body, { childList: true, subtree: true });
        this.refreshAvailability();
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
        if (country === this.renderedForCountry) {
            return; // already settled for this country
        }
        if (country in this.availabilityByCountry) {
            this.apply(country, this.availabilityByCountry[country]);
            return;
        }
        const self = this;
        fetch(this.moduleUrl('soleTraderAvailability') + '&country=' + encodeURIComponent(country), { method: 'GET' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                const available = !!(json && json.success && json.available);
                self.availabilityByCountry[country] = available;
                // The buyer may have changed country mid-request; only
                // apply if the answer is still for the current one.
                if (self.billingCountry() === country) {
                    self.apply(country, available);
                }
            })
            .catch(function () {
                if (self.billingCountry() === country) {
                    self.apply(country, false);
                }
            });
    }

    apply(country, available) {
        this.renderedForCountry = country;
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
                    self.showStatus(companyLabel);
                    // Feed the tile's read-only company summary (TWO-25288).
                    // Pushed rather than read from the DOM because this flow
                    // writes neither the address form's `company` input nor the
                    // hidden `companyid` one - the enrolled pair only ever
                    // exists in this response and in the server session, so
                    // there is nothing on the page for the summary to find.
                    // The name may legitimately be blank here: a sole trader
                    // often trades under their own name, which is exactly why
                    // companyLabel above falls back to the number.
                    try {
                        if (window.TwoCompanySummary && typeof window.TwoCompanySummary.setSoleTrader === 'function') {
                            window.TwoCompanySummary.setSoleTrader({
                                name: buyer.company_name || '',
                                number: buyer.organization_number || ''
                            });
                        }
                    } catch (e) {
                        // Display only; never fail the enrolment over it.
                    }
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
