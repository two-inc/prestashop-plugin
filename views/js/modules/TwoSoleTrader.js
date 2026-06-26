/**
 * Two Sole Trader - presentation layer for the sole-trader checkout flow
 * (TWO-24755).
 *
 * Renders a Business / Sole trader toggle on the payment step — the same
 * model as the Magento and WooCommerce plugins — shown only where Two's
 * registry says the billing country supports sole traders AND the merchant
 * has enabled the feature. There is no account-type selector on the address
 * form; the buyer always enters company details (B2B), and sole traders
 * enrol from this toggle.
 *
 * Picking "Sole trader" mints the delegation + autofill tokens server-side,
 * opens Two's hosted signup popup, and autofills the company fields from
 * GET /autofill/v1/buyer/current. An enrolled sole trader then checks out as
 * a regular business — the synthetic organization number their registration
 * minted carries the semantics, so the order payload is unchanged.
 *
 * All decisioning (country eligibility, token minting) lives server-side in
 * classes/TwoSoleTrader.php; this module only renders the result.
 */
class TwoSoleTrader {
    constructor(config) {
        this.config = {
            enabled: false,
            checkoutHost: '',
            orderIntentUrl: '',
            ajaxToken: '',
            signupUrl: '',
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

        if (this.config.enabled) {
            this.init();
        }
    }

    init() {
        const self = this;
        // The payment box and the billing country can both re-render across
        // checkout step transitions; re-evaluate availability on each change.
        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches("select[name='id_country'], select[name='country']")) {
                self.refreshAvailability();
            }
        });
        const observer = new MutationObserver(function () {
            self.refreshAvailability();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        this.refreshAvailability();
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
            const iso = field.selectedOptions[0].getAttribute('data-iso-code');
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
                // The buyer may have changed country mid-request; only apply
                // if the answer is still for the current one.
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
     * Switch mode. Sole trader mints tokens then autofills (or prompts the
     * signup popup) once per page; business just hides the prompt. The popup
     * opens from the prompt link's own click so the blocker lets it through.
     */
    setMode(mode) {
        this.mode = mode;
        this.updateChips();
        if (mode === 'sole_trader') {
            if (!this.flowStarted) {
                this.flowStarted = true;
                this.fetchTokens();
            } else if (this.tokens) {
                this.getCurrentBuyer();
            }
        } else {
            this.hidePrompt();
        }
    }

    fetchTokens() {
        const self = this;
        fetch(this.moduleUrl('soleTraderTokens'), { method: 'POST' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json && json.success && json.autofill_token) {
                    self.tokens = json;
                    self.bindPopupMessageListener();
                    self.getCurrentBuyer();
                } else {
                    self.showError();
                }
            })
            .catch(function () {
                self.showError();
            });
    }

    /**
     * Autofill from the buyer's current Two sole-trader business. A 404 or
     * an email mismatch means no usable registration yet - show the signup
     * prompt instead.
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
            .then(function (json) {
                const emailField = document.querySelector("input[name='email'], #email");
                const checkoutEmail = emailField ? emailField.value : '';
                if (json && (!checkoutEmail || json.email === checkoutEmail)) {
                    self.applyBuyer(json);
                } else {
                    self.showPrompt();
                }
            })
            .catch(function () {
                self.showError();
            });
    }

    /**
     * Persist the enrolled sole trader's company data through the existing
     * saveCompany cookie action; the order-intent and payment paths then
     * pick it up via the regular company-data fallback chain.
     */
    applyBuyer(buyer) {
        const self = this;
        const country = this.billingCountry();
        const body = new URLSearchParams({
            company: buyer.company_name || '',
            companyid: buyer.organization_number || '',
            country: country
        });
        fetch(this.moduleUrl('saveCompany'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json && json.success) {
                    self.showStatus(buyer.company_name || buyer.organization_number);
                    self.hidePrompt();
                    document.dispatchEvent(new CustomEvent('two:sole-trader-ready'));
                } else {
                    self.showError();
                }
            })
            .catch(function () {
                self.showError();
            });
    }

    openPopup() {
        if (!this.tokens) {
            return;
        }
        const firstName = document.querySelector("input[name='firstname']");
        const lastName = document.querySelector("input[name='lastname']");
        const email = document.querySelector("input[name='email'], #email");
        const phone = document.querySelector("input[name='phone']");
        const prefill = {
            email: email ? email.value : '',
            first_name: firstName ? firstName.value : '',
            last_name: lastName ? lastName.value : '',
            phone_number: phone ? phone.value : ''
        };
        const url =
            this.tokens.signup_url +
            '?businessToken=' + encodeURIComponent(this.tokens.delegation_token) +
            '&autofillToken=' + encodeURIComponent(this.tokens.autofill_token) +
            '&autofillData=' + encodeURIComponent(btoa(JSON.stringify(prefill)));
        window.open(
            url,
            '_blank',
            'location=yes,resizable=yes,scrollbars=yes,status=yes,height=805,width=610'
        );
    }

    /**
     * The hosted signup posts 'ACCEPTED' back to the opener when the buyer
     * completes registration; re-fetch the buyer to autofill.
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
            } else {
                self.showError();
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
