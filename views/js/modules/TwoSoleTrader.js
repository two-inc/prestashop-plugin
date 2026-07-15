/**
 * Two Sole Trader - presentation layer for the sole-trader checkout flow
 * (TWO-24755).
 *
 * All decisioning lives server-side in classes/TwoSoleTrader.php (whether
 * the option shows for a country, token minting); this module only reacts
 * to the buyer's account_type, opens Two's hosted signup popup, autofills
 * the company data from GET /autofill/v1/buyer/current, and persists it
 * through the existing saveCompany action so the regular order-intent and
 * payment paths run unchanged. Mirrors the WooCommerce/Magento plugins'
 * flow: enrolment happens in the hosted popup, which posts 'ACCEPTED'
 * back to this window; the enrolled buyer's TWO:ST organization number
 * carries the sole-trader semantics.
 */
class TwoSoleTrader {
    constructor(config) {
        this.config = {
            enabled: false,
            checkoutHost: '',
            orderIntentUrl: '',
            ajaxToken: '',
            ...config
        };
        this.tokens = null;
        this.flowStarted = false;
        this.messageListenerBound = false;
        this.observer = null;
        // In-flight guard + cooldown: the MutationObserver below fires on
        // essentially any DOM change during checkout (spinners, unrelated
        // widget re-renders). Without these, a persistent token-mint
        // failure (no invoice address yet, registry down, rate-limited...)
        // would re-invoke fetchTokens() - two upstream Two API calls - on
        // every single mutation, with overlapping in-flight requests and
        // no backoff. isFetchingTokens blocks re-entry while a request is
        // outstanding; nextRetryAt enforces a minimum gap between attempts.
        this.isFetchingTokens = false;
        this.nextRetryAt = 0;
        this.retryCooldownMs = 5000;

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
        this.observer = new MutationObserver(function () {
            self.evaluate();
        });
        this.observer.observe(document.body, { childList: true, subtree: true });
        this.evaluate();
    }

    /**
     * Once the flow has resolved (enrolled company saved) there is nothing
     * left for this instance to react to; stop observing rather than
     * running a body-wide observer for the rest of checkout.
     */
    stopObserving() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    }

    accountType() {
        const field = document.querySelector("select[name='account_type']");
        if (field && field.value) {
            return field.value;
        }
        // The address form is gone at the payment step; TwoCheckoutManager
        // mirrors the selection into sessionStorage on every change.
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
     * Show/hide the sole-trader block and kick the flow off when the buyer
     * is in sole-trader mode at the payment step. Retries on re-evaluation
     * (e.g. address step -> payment step transition, or the buyer leaving
     * and re-entering sole-trader mode) whenever the previous attempt
     * didn't leave us with usable tokens - a transient failure must not
     * strand the buyer until a full page reload. fetchTokens() itself
     * guards against overlapping requests and enforces a cooldown, so this
     * can be called as often as the (body-wide) MutationObserver fires
     * without risking a request storm.
     */
    evaluate() {
        const container = this.container();
        if (!container) {
            return;
        }
        if (this.accountType() === 'sole_trader') {
            container.style.display = 'block';
            if (!this.flowStarted || !this.tokens) {
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

    /**
     * Mint tokens, guarded against the retry-on-every-mutation path above
     * turning into a request storm: refuses re-entry while a request is
     * already outstanding (isFetchingTokens) and enforces a minimum gap
     * between attempts after a failure (nextRetryAt/retryCooldownMs).
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
     * (present once the personal-information step is done) with the email
     * form field as fallback.
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
     * a missing checkout email or an email mismatch means no usable
     * registration yet - show the signup prompt instead. The email match
     * is case-insensitive and required, the same contract the WooCommerce
     * and Magento plugins apply.
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
     * Persist the enrolled sole trader's company data through the existing
     * saveCompany cookie action; the order-intent and payment paths then
     * pick it up via the regular company-data fallback chain.
     *
     * Two things a naive implementation gets wrong here:
     *  - company_name may be BLANK for a sole trader (they often trade
     *    under their own name, not a company name); saveCompany rejects an
     *    empty company, so fall back to the organization number - the
     *    order-intent payload only needs a non-empty, non-blank pair, and
     *    the org-number prefix is what carries the sole-trader semantics
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
     * in names), so encode to UTF-8 bytes first - same as the WooCommerce
     * plugin; the signup page decodes base64-of-UTF-8 either way.
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
        // Tokens travel in the popup URL's query string (browser history,
        // Referer to third-party assets on the signup page) - the same
        // trade-off the WooCommerce plugin makes. Acceptable here because
        // both tokens are scoped to this buyer and short-lived; a
        // meaningfully more private delivery (postMessage/fragment) would
        // require the hosted signup page to support it first.
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
     * The hosted signup posts 'ACCEPTED' back to the opener when the buyer
     * completes registration; re-fetch the buyer to autofill. Origin must
     * be the signup page's own. Any other message from that origin is
     * ignored rather than treated as a failure - the signup page may emit
     * unrelated messages (resize/analytics) that are none of our business,
     * and only an explicit ACCEPTED is a signal we act on.
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
