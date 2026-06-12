/**
 * Two Sole Trader - presentation layer for the sole-trader checkout flow
 * (TWO-24755).
 *
 * All decisioning lives server-side in classes/TwoSoleTrader.php (whether
 * the option shows for a country, token minting); this module only reacts
 * to the buyer's account_type, opens Two's hosted signup popup, autofills
 * the company data from GET /autofill/v1/buyer/current, and persists it
 * through the existing saveCompany action so the regular order-intent and
 * payment paths run unchanged. Mirrors the Magento reference flow.
 */
class TwoSoleTrader {
    constructor(config) {
        this.config = {
            enabled: false,
            checkoutHost: '',
            orderIntentUrl: '',
            ajaxToken: '',
            signupUrl: '',
            ...config
        };
        this.tokens = null;
        this.flowStarted = false;
        this.messageListenerBound = false;

        if (this.config.enabled) {
            this.init();
        }
    }

    init() {
        const self = this;
        // The account_type select lives on the address step; the payment
        // box renders later. Watch both: select changes and DOM arrival of
        // the payment container (checkout step transitions re-render).
        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches("select[name='account_type']")) {
                self.evaluate();
            }
        });
        const observer = new MutationObserver(function () {
            self.evaluate();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        this.evaluate();
    }

    accountType() {
        const field = document.querySelector("select[name='account_type']");
        if (field && field.value) {
            return field.value;
        }
        try {
            return sessionStorage.getItem('two_account_type') || '';
        } catch (e) {
            return '';
        }
    }

    container() {
        return document.querySelector('.two-sole-trader');
    }

    /**
     * Show/hide the sole-trader block and kick the flow off exactly once
     * per page when the buyer is in sole-trader mode at the payment step.
     */
    evaluate() {
        const container = this.container();
        if (!container) {
            return;
        }
        if (this.accountType() === 'sole_trader') {
            container.style.display = 'block';
            if (!this.flowStarted) {
                this.flowStarted = true;
                this.fetchTokens();
            }
        } else {
            container.style.display = 'none';
        }
    }

    moduleUrl(action) {
        const url = new URL(this.config.orderIntentUrl, window.location.origin);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('action', action);
        url.searchParams.set('token', this.config.ajaxToken);
        return url.toString();
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

    billingCountry() {
        const field = document.querySelector("select[name='id_country'], select[name='country']");
        if (field && field.selectedOptions && field.selectedOptions.length) {
            const iso = field.selectedOptions[0].getAttribute('data-iso-code');
            if (iso) {
                return iso;
            }
        }
        return (window.twopayment && window.twopayment.shop_country) || '';
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
            if (self.accountType() !== 'sole_trader' || !self.tokens) {
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
