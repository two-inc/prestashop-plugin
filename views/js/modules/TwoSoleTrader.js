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
 *
 * `.two-sole-trader` ONLY EVER exists on the payment step (it is rendered by
 * paymentinfo.tpl and nowhere else) - the address-editor page has no such
 * element at all (TWO-40 follow-up; live bug reported by Doug). Resolving
 * availability must not depend on that container existing: refreshAvailability()
 * below resolves and caches the answer for whatever page it runs on, and only
 * the enrolment-status rendering (showPrompt()/hidePrompt()/showStatus()/
 * showError()) - which genuinely has nowhere to draw without it - stays
 * gated on the container being present. See refreshAvailability()'s own doc.
 *
 * The availability answer is ALSO cached in localStorage per ISO country
 * code with a 24h TTL (Doug's own follow-up request), namespaced by
 * `config.checkoutHost` so staging/production/sandbox never share an entry -
 * see availabilityStorageKey()'s doc for why that split is the right one and
 * not a finer, per-merchant one. This is what lets the FIRST page a buyer
 * lands on skip the round trip entirely once any earlier page (or an earlier
 * visit, within the TTL) has already resolved that country.
 */
class TwoSoleTrader {
    /**
     * How long a persisted availability answer (see
     * readPersistedAvailability()/writePersistedAvailability()) stays valid -
     * an explicit 24h, per Doug's own request (TWO-40 follow-up): the
     * registry round trip fired on every fresh page load was the visible
     * latency this cache exists to cut, and 24h bounds how long a stale
     * answer can survive a real registry change.
     *
     * Deliberately NOT claimed to match the server-side registry cookie's
     * own cache window (`classes/TwoSoleTrader.php::CACHE_TTL_SECONDS`,
     * currently 3600s/1h - adversarial review, "Han" finding: an earlier
     * draft of this comment claimed the two "closely" agreed, which is off
     * by a factor of 24 and was never actually checked against the PHP
     * constant). The two TTLs are independent by design: this one bounds
     * the CLIENT's browser cache, the server one bounds ITS OWN registry
     * lookup: a shorter server TTL just means the server itself may re-ask
     * the registry several times inside one client TTL window, which is a
     * server-side cost, not a correctness gap here - whichever one answers
     * first is what this cache is populated from.
     */
    // Underscore-prefixed to match this file's/TwoCompanySearch.js's own
    // convention for an internal-only static (adversarial review, "Yoda"
    // finding) - this is read by nobody outside readPersistedAvailability().
    static _AVAILABILITY_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

    // TWO-40 follow-up, Doug: a buyer who sits on checkout long enough for
    // the delegated auth tokens to expire server-side loses autofill and the
    // sole-trader flow entirely. See startTokenRefreshInterval()/
    // refreshTokens().
    //
    // Unlike `_AVAILABILITY_CACHE_TTL_MS` above, there is no local server-side
    // constant to cite here: the delegated tokens' own TTL is set upstream, by
    // whatever mints delegation_token/autofill_token (classes/TwoSoleTrader.php's
    // mint call), not by this module or its immediate PHP counterpart. 30
    // minutes is Doug's own considered figure (this ticket's ask verbatim),
    // not derived from a constant this file can point at.
    static _TOKEN_REFRESH_INTERVAL_MS = 30 * 60 * 1000;

    constructor(config) {
        this.config = {
            checkoutHost: '',
            orderIntentUrl: '',
            ajaxToken: '',
            // The country of the cart's OWN billing address, resolved server-side
            // (TWO-25326 bug 9, round 3 adversarial review). See billingCountry().
            billingCountry: '',
            shopCountry: '',
            // Translated fallback for showStatus() when an enrolled buyer's
            // registration carries no displayable company name or number.
            // The only i18n this module needs since TWO-40 removed the chip
            // labels - see applyBuyer().
            statusLabel: '',
            ...config
        };
        // Whether an enrolment (signup popup + autofill) is currently under
        // way. Replaces the old chip's 'business'/'sole_trader' mode - there
        // is no second mode to switch back to any more, just "not enrolling"
        // and "enrolling".
        this.enrolling = false;
        // Bumped by cancelEnrollment() every time it is called, whatever
        // `enrolling` was (round 2 adversarial review finding). A
        // getCurrentBuyer() call captures this before it starts and
        // applyBuyer() refuses to publish a selection if it has moved -
        // see cancelEnrollment()'s own comment for the buyer-facing failure
        // this closes.
        this._enrollGeneration = 0;
        // The `_enrollGeneration` value that was current when `this.tokens`
        // was last minted or explicitly resumed (round 3 adversarial review
        // finding). `cancelEnrollment()` deliberately does NOT invalidate
        // `tokens` or close the signup popup - the flow is meant to be
        // resumable - so the popup-completion listener has to be able to
        // tell "these tokens belong to the CURRENT attempt" from "these
        // tokens are from an attempt the buyer has since walked away from,
        // and the popup just happens to still be open" before it reacts to
        // anything. See fetchTokens()/startEnrollment() (where this is
        // stamped) and bindPopupMessageListener() (where it is checked).
        this._tokensGeneration = 0;
        this.tokens = null;
        this.flowStarted = false;
        // The handle returned by window.open() for the currently-open signup
        // popup, and the setInterval id polling it - held so
        // notifyEnrollmentSettled() can hold off until the popup actually
        // closes rather than just handing off to it. See openPopup()/
        // watchPopupUntilClosed() (TWO-40 follow-up, Doug: the query-field
        // spinner was clearing as soon as window.open() returned).
        this._popup = null;
        this._popupPollInterval = null;
        // Set by startReplacement() ("Select a different sole trader"),
        // consumed by afterTokensReady() once tokens are ready. Tells that
        // point to skip getCurrentBuyer()'s silent-autofill check and go
        // straight to the popup - see startReplacement()'s own comment.
        this._skipAutofillCheck = false;
        this.messageListenerBound = false;
        // Held so destroy() can detach it. See bindPopupMessageListener().
        this._messageHandler = null;
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
        // Same shape as isFetchingTokens above, for getCurrentBuyer()
        // (TWO-40 round 5, adversarial review finding) - see its own guard.
        this.isFetchingBuyer = false;
        // Set by bindPopupMessageListener()'s 'ACCEPTED' handler when a
        // genuine authentication event arrives while isFetchingBuyer is
        // already true for a different call (TWO-40 round 8, adversarial
        // review finding, Han) - see getCurrentBuyer()'s own .finally().
        this._pendingTrustedResume = false;
        // How many applyBuyer() write-backs are still out. A COUNT, not a
        // boolean: a generation bump can leave two overlapping saveCompany
        // round trips in the air, and the first one's `.finally()` must not
        // report the second one finished. See isWriteRoundTripOutstanding().
        this._pendingAdoptionWrites = 0;
        // A notifyEnrollmentSettled() call that isWriteRoundTripOutstanding()
        // held back, to be re-fired once it isn't. See flushDeferredSettle().
        this._settleDeferred = false;
        // The setInterval handle for the 30-minute background token refresh
        // (TWO-40 follow-up) - held so destroy() can clear it. Started once,
        // by fetchTokens()'s own success branch, on the first real mint; see
        // startTokenRefreshInterval().
        this._tokenRefreshIntervalId = null;
        // Set by destroy() (TWO-40 follow-up, round 2 adversarial review,
        // Leia finding): a fetchTokens() mint still outstanding when
        // destroy() runs (e.g. PrestaShop swaps in a fresh instance for a
        // replaced payment fragment) must not arm a NEW setInterval on the
        // now-dead instance when it resolves - nothing will ever call
        // destroy() on it again to clear it. Checked at the top of
        // fetchTokens()'s success branch.
        this._destroyed = false;

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
        const available = (answer === '1');
        this.availabilityByCountry[country] = available;
        this.renderedForCountry = country;
        this.renderedContainer = container;
        this._adoptGeneration += 1;
        // A server-rendered answer is a genuine registry answer, exactly like
        // a successful soleTraderAvailability response - persist it the same
        // way so a LATER page with no `.two-sole-trader` container (e.g. the
        // address-editor page, TWO-40 follow-up) can paint from cache too,
        // not only a page that happens to render this container itself.
        //
        // Skipped when the persisted cache already agrees (adversarial
        // review, "Han" finding). adoptReplacedContainer() calls this method
        // EVERY time PrestaShop swaps `.two-sole-trader` for a fresh copy,
        // undebounced, from the MutationObserver callback - and per this
        // file's own comments that happens "constantly" while a checkout
        // step settles. Most of those replacements carry the SAME answer as
        // the one before, so writing to localStorage on every single one is
        // a burst of synchronous main-thread writes for no state change.
        // readPersistedAvailability() already re-validates freshness, so a
        // stale-but-matching entry still gets its `ts` refreshed rather than
        // being (wrongly) treated as "no write needed".
        //
        // Asymmetric, because the two answers are stored asymmetrically: a
        // negative is REMOVED rather than written (writePersistedAvailability()),
        // so a stored negative does not exist and `persisted` can never read
        // back `false`. Comparing it to `available` directly made this guard a
        // no-op for every negative answer - a redundant synchronous removeItem
        // on each of the replacements it exists to absorb. A negative has work
        // to do only when there is an entry to remove.
        const persisted = this.readPersistedAvailability(country);
        if (available ? persisted !== true : persisted !== null) {
            this.writePersistedAvailability(country, available);
        }
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
        this._destroyed = true;
        this.stopObserving();
        this.stopPopupWatch();
        this.stopTokenRefreshInterval();
        this._popup = null;
        if (this._countryChangeHandler) {
            document.removeEventListener('change', this._countryChangeHandler);
            this._countryChangeHandler = null;
        }
        if (this._messageHandler) {
            window.removeEventListener('message', this._messageHandler);
            this._messageHandler = null;
        }
        this.messageListenerBound = false;
        // A deferred settle belongs to the flight that deferred it, and this
        // instance is not going to finish that flight - drop it rather than
        // let a late `.finally()` dispatch it over whatever the buyer is
        // doing by then. `_pendingAdoptionWrites` is deliberately NOT reset:
        // an outstanding applyBuyer() still owns its own decrement, and
        // zeroing the count here would just drive it negative.
        this._settleDeferred = false;
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

    /**
     * Append the `client`/`client_v` identification params that every call to
     * Two carries, read from the server-published config. The client id and the
     * version are never restated here, so a version bump stays a PHP-only
     * change.
     *
     * Query params rather than a body field, on the POSTs too: that is the
     * convention the module's own server-side calls already use for this pair
     * (getTwoClientParams() / setTwoPaymentRequest() in twopayment.php attach
     * them to the URL on POST and PUT as well as GET).
     *
     * Only for calls to Two's own host. The shop-internal URLs moduleUrl()
     * builds are a different server and do not take these params.
     *
     * Either param is dropped when the config does not carry it, so a page that
     * somehow runs without the config sends a correct URL rather than a literal
     * `client=undefined`.
     *
     * @param {string} url
     * @returns {string} url with the params appended
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
            // TWO-40 follow-up: this fallback was missing here despite the
            // comment above CLAIMING parity with TwoOrderIntent.js/
            // TwoCompanySearch.js - it jumped straight to `this.config`
            // (page-load-time, never updated on a later country change)
            // instead. On any theme/PS version whose country <option>s carry
            // none of the three data- attributes above - exactly why the
            // other two modules needed this fallback in the first place -
            // billingCountry() returned the ORIGINAL page-load country
            // forever, however many times the buyer changed it: this and
            // isAvailableForCurrentCountry() both silently kept answering for
            // the WRONG country, with no error and no re-fetch, since the
            // (wrong) country was already "settled" in availabilityByCountry.
            // `window.twopayment.countries` is the server-built id -> ISO map
            // (`Country::getCountries()`, injected via `Media::addJsDef`)
            // TwoCompanySearch.getCurrentCountry() and TwoOrderIntent.js's
            // getCurrentAddressCountryISO() both already read.
            const countryId = option.value;
            const isoFromConfig = (window.twopayment && window.twopayment.countries)
                ? window.twopayment.countries[countryId]
                : null;
            if (isoFromConfig) {
                return String(isoFromConfig).toUpperCase();
            }
            // Last DOM-derived attempt, for a theme that renders its own
            // select with none of the above and loads this module without
            // the server-built map - same map TwoCompanySearch.js's
            // extractCountryFromText() uses.
            const fromText = TwoSoleTrader.extractCountryFromOptionText(option.textContent);
            if (fromText) {
                return fromText;
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
     * Same map as TwoCompanySearch.js's extractCountryFromText() (TWO-40
     * follow-up) - kept here rather than shared, since these are two
     * independently-loaded modules with no common utility file between them.
     * MIRRORED there; keep the two in step by hand.
     *
     * nl/no/sv entries added (adversarial review finding, TWO-40 follow-up
     * round 2) - see the identical comment on the sibling copy in
     * TwoCompanySearch.js for why: this shop ships those three locales, and
     * an English/Spanish/French-only map left this fallback blind for all of
     * them.
     *
     * @param {string} text visible text of the selected <option>
     * @returns {?string} uppercase ISO code, or null
     */
    static extractCountryFromOptionText(text) {
        const countryMap = {
            'united kingdom': 'GB', 'great britain': 'GB', 'uk': 'GB', 'england': 'GB',
            'verenigd koninkrijk': 'GB', 'storbritannia': 'GB', 'storbritannien': 'GB',
            'spain': 'ES', 'españa': 'ES', 'espagne': 'ES',
            'spanje': 'ES', 'spania': 'ES', 'spanien': 'ES',
            'france': 'FR', 'francia': 'FR', 'frankrijk': 'FR', 'frankrike': 'FR',
            'germany': 'DE', 'deutschland': 'DE', 'alemania': 'DE',
            'duitsland': 'DE', 'tyskland': 'DE',
            'italy': 'IT', 'italia': 'IT', 'italie': 'IT', 'italië': 'IT', 'italien': 'IT',
            'netherlands': 'NL', 'holland': 'NL', 'países bajos': 'NL',
            'nederland': 'NL', 'nederländerna': 'NL',
            'belgium': 'BE', 'bélgica': 'BE', 'belgique': 'BE',
            'belgië': 'BE', 'belgia': 'BE', 'belgien': 'BE',
            'united states': 'US', 'usa': 'US', 'estados unidos': 'US', 'verenigde staten': 'US',
            'canada': 'CA', 'canadá': 'CA',
            'australia': 'AU', 'australië': 'AU', 'australien': 'AU'
        };
        const lowerText = String(text || '').toLowerCase().trim();
        return countryMap[lowerText] || null;
    }

    /**
     * Resolve availability for the current billing country and cache it.
     * Availability is resolved server-side; fail-soft to "not available" so
     * checkout never blocks.
     *
     * Deliberately does NOT require `.two-sole-trader` to exist on the page
     * (TWO-40 follow-up, live bug on the address-editor page). That container
     * only ever hosts enrolment prompt/status/error messaging (see the class
     * doc) - resolving and caching the availability ANSWER is a completely
     * separate concern from having somewhere to draw enrolment UI, and
     * TwoCompanySearch.js's isAvailableForCurrentCountry() read has no
     * dependency on that container at all. Early-returning here when it was
     * absent meant `availabilityByCountry` never resolved for ANY country on
     * any page that never renders it - which is every page except the payment
     * step - so the "I'm a sole trader" row could never appear anywhere else,
     * regardless of country. apply() (called below, and from the adopt paths)
     * still resolves `this.container()` itself and every render helper it
     * calls (showPrompt()/hidePrompt()/showStatus()/showError()) already
     * null-guards on it, so this degrades to "no enrolment UI, correct
     * availability" rather than throwing.
     */
    refreshAvailability() {
        // Also here, not only in the observer callback: this method is reached
        // from the country-change listener too, which can fire after a fragment
        // replacement the observer's debounce has not drained yet. Idempotent.
        // A no-op when there is no container at all (see above).
        this.adoptReplacedContainer();
        const country = this.billingCountry();
        if (!country) {
            this.apply('', false);
            return;
        }
        const container = this.container();
        // Settled means "this container, for this country" - not the country
        // alone (TWO-25326 bug 9). A country-only check reported the answer as
        // settled after PrestaShop had replaced the container out from under it,
        // until some unrelated later trigger happened to re-adopt it - which is
        // the render / disappear / reappear cycle Doug saw on the old chips,
        // distinct from the tile-level mount/unmount guard. Guarded on
        // `container` truthy (TWO-40 follow-up): with no container at all there
        // is nothing to have settled INTO, so this must fall through to the
        // in-memory/persisted-cache/fetch checks below every time, exactly as a
        // page that has never resolved this country would.
        if (container && country === this.renderedForCountry && this.isSettledFor(container)) {
            return;
        }
        if (country in this.availabilityByCountry) {
            this.apply(country, this.availabilityByCountry[country]);
            return;
        }
        // Persistent cross-page-load cache, per Doug's request (TWO-40
        // follow-up): checked BEFORE the network fetch, so a fresh page load -
        // address editor, payment step, or a later visit - can paint the "I'm a
        // sole trader" row on first evaluation instead of waiting out another
        // round trip for an answer this browser already has. A miss (absent,
        // malformed, or expired) falls through to the fetch exactly as before.
        const persisted = this.readPersistedAvailability(country);
        if (persisted !== null) {
            this.availabilityByCountry[country] = persisted;
            this.apply(country, persisted);
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
                // `success: false` is NOT an answer about this country
                // (TWO-40 follow-up, Doug live-test finding: the sole-trader
                // chip stopped rendering for GB). This endpoint answers
                // `{success: false, error: ...}` for a stale/absent ajax token
                // and for an unknown action - a 200 with a JSON body, so the
                // catch() below never sees it - and flattening that into
                // `available: false` cached it as a definite "this country does
                // not do sole traders": for the page's life in memory, and for a
                // FULL DAY in localStorage, on every later page load, with
                // nothing that would ever re-ask. One expired token was enough
                // to remove the chip for 24h. Same posture as the transport
                // failure below instead: nothing cached, pending marker already
                // released, so the next mutation batch or country change asks
                // again.
                if (!json || !json.success) {
                    if (self.billingCountry() === country) {
                        self.apply(country, false);
                    }
                    return;
                }
                const available = !!json.available;
                self.availabilityByCountry[country] = available;
                // A resolved server response - true or false - is a real
                // answer about this country, unlike the transport-failure
                // path below; persist it (TWO-40 follow-up, 24h TTL).
                self.writePersistedAvailability(country, available);
                // The buyer may have changed country mid-request; only
                // apply if the answer is still for the current one.
                if (self.billingCountry() === country) {
                    self.apply(country, available);
                }
            })
            .catch(function () {
                // NOT cached - in EITHER cache, in-memory or persisted: a
                // transport failure is not an answer about this country, and
                // caching it would make one blip permanent for the rest of the
                // page's life (in-memory) or for a full day (persisted). Only
                // the pending marker clears, so a later mutation or country
                // change can ask again.
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

    /**
     * The localStorage key an availability answer for `country` is stored
     * under (TWO-40 follow-up).
     *
     * Namespaced by `config.checkoutHost` (e.g.
     * https://checkout.two.inc vs https://checkout.staging.two.inc vs a
     * sandbox host), NOT further by shop or merchant. The registry answer
     * behind this cache is resolved per classes/TwoSoleTrader.php's
     * `isAvailable()` from `GET /registry/v1/supported-company-types/<ISO>` -
     * country-level legal truth with, by that class's own doc, "deliberately
     * no merchant admin toggle": two merchants talking to the SAME
     * environment always get the same answer for a given country, so
     * namespacing any finer would only fragment the cache for no safety
     * benefit. The environment split is real, though: staging and
     * production (and any sandbox) are separate Two backends that can
     * legitimately disagree on which countries are enrolled, and staging/dev
     * shops on this repo are routinely tested from one shared browser
     * profile (`.worktrees/*` dev shops, staging.two.inc) - so an answer
     * cached for one environment must never be read back for another.
     * `checkoutHost` is already resolved per-environment server-side
     * (Twopayment::getTwoCheckoutHostUrl(), handed over as
     * `window.twopayment.checkout_host`) and is already on `this.config`,
     * so no new server-side plumbing is needed to get it.
     *
     * Returns null when `checkoutHost` is not known yet (adversarial review,
     * "Vader" finding): TwoCompanySearch.js already treats a missing
     * `checkoutHost` as "nothing safe to do here" for the same reason
     * (`if (!this.config.checkoutHost) return;`) - the environment split
     * above is the whole point of this cache, and defaulting to a shared
     * `''` segment when it is absent would let two DIFFERENT, unidentified
     * environments silently read and overwrite each other's answers, which
     * is exactly the cross-contamination this namespacing exists to
     * prevent. Callers must treat a null return as "cache unavailable", not
     * as a key to use.
     *
     * @param {string} country ISO-2, already uppercased by the caller
     * @returns {?string}
     */
    availabilityStorageKey(country) {
        if (!this.config.checkoutHost) {
            return null;
        }
        return 'two_sole_trader_availability::' + this.config.checkoutHost + '::' + country;
    }

    /**
     * The cached availability answer for `country`, if one is on record and
     * still inside the 24h TTL - null on a miss, an expired entry, or
     * anything unparseable (an older cache-format version, a theme/extension
     * writing to the same key, corrupted storage). Never throws: a buyer
     * with localStorage disabled (private browsing, quota exceeded, some
     * locked-down browser policy) degrades to exactly today's fetch-every-load
     * behaviour rather than breaking anything.
     *
     * @param {string} country ISO-2, already uppercased by the caller
     * @returns {?boolean}
     */
    readPersistedAvailability(country) {
        try {
            const key = this.availabilityStorageKey(country);
            if (!key) {
                return null;
            }
            const raw = window.localStorage.getItem(key);
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.available !== 'boolean' || typeof parsed.ts !== 'number') {
                return null;
            }
            // A FUTURE `ts` (adversarial review, "Vader"/"Yoda" finding: a
            // corrected/skewed system clock, or a value planted by another
            // same-origin script) must not be treated as fresher than now -
            // `Date.now() - parsed.ts` would be negative and this entry would
            // never expire, pinning one answer for a country indefinitely
            // regardless of what the registry actually says. Reject it
            // outright rather than clamping it forward: a cache write this
            // module itself never makes (it always stamps `Date.now()` at
            // write time) is not an answer to trust at all.
            const age = Date.now() - parsed.ts;
            if (age < 0 || age >= TwoSoleTrader._AVAILABILITY_CACHE_TTL_MS) {
                return null;
            }
            return parsed.available;
        } catch (e) {
            return null;
        }
    }

    /**
     * Persist a REAL availability answer for `country` - never a
     * transport-failure fallback (see the callers: the fetch success
     * handler and adoptServerRenderedToggle(), never the fetch catch()).
     * Best-effort and silent: a write failure (quota, disabled storage) must
     * not affect checkout, only the latency this cache is there to cut.
     *
     * A NEGATIVE answer REMOVES any stored entry rather than storing itself
     * (TWO-40 follow-up, Doug live-test finding). This cache exists to paint an
     * available chip on first evaluation instead of waiting out a round trip -
     * there is nothing to paint faster for a country that has no chip, so
     * storing "no" buys nothing, while a stored "no" costs a full day of the
     * chip staying gone after the answer behind it changes (a registry country
     * being enrolled, a merchant's environment being fixed) with nothing that
     * re-asks inside the TTL. Removing rather than merely skipping the write
     * matters: skipping would leave an earlier stored "yes" standing for up to
     * 24h after the country stopped being eligible, which is the same bug
     * pointing the other way. Either way the answer is still cached IN MEMORY
     * for the page's life by the callers, so no extra request is made per page.
     *
     * @param {string} country ISO-2, already uppercased by the caller
     * @param {boolean} available
     * @returns {void}
     */
    writePersistedAvailability(country, available) {
        try {
            const key = this.availabilityStorageKey(country);
            if (!key) {
                return;
            }
            if (!available) {
                window.localStorage.removeItem(key);
                return;
            }
            window.localStorage.setItem(key, JSON.stringify({ available: true, ts: Date.now() }));
        } catch (e) {
            // no-op: presentation-only cache, never a gate.
        }
    }

    apply(country, available) {
        this.renderedForCountry = country;
        this.renderedContainer = this.container();
        // An enrolment in progress for a country that has just stopped being
        // eligible (buyer changed country mid-flow) must not keep showing a
        // prompt/status for it - mirrors the old chip's hide()-forces-business
        // behaviour, without a "business" mode to fall back to.
        //
        // The full abandon, popup included: the tokens this popup is signing
        // against were minted for the country that just stopped being
        // eligible (mintTokensRequest()), so there is nothing for the buyer to
        // usefully finish in it.
        if (!available && this.enrolling) {
            this.abandonEnrollment();
        }
        this.resyncSoleTraderChip();
    }

    /**
     * Tell the company-search control to re-evaluate its "Sole trader" chip
     * now that an availability answer has landed (TWO-40 follow-up, Doug
     * live-test finding: the chip did not render at all on a GB address step).
     *
     * The chip is a child of the search dropdown and TwoCompanySearch decides
     * its visibility by READING this instance's cache
     * (isAvailableForCurrentCountry()) at the moments it already re-evaluates
     * its own rows - the panel opening, an address-form re-render, an adoption.
     * Availability resolves ASYNCHRONOUSLY, off a round trip this module owns,
     * and nothing pushed the answer the other way: a buyer who reached the
     * company field before that request landed - which is every first
     * evaluation on a page with no server-rendered answer to adopt and no
     * persisted one to read, i.e. the whole address step - got a panel with no
     * Sole trader chip in it and nothing to add one while it stayed open.
     *
     * Resolved lazily through the manager, exactly as adoptEnrolledIdentity()
     * does and for the same reason: the manager destroys and rebuilds its
     * search instance on every `updatedAddressForm`. Fails soft - a missing
     * instance costs this repaint, not the answer, which is already cached
     * above by the time this runs.
     *
     * WooCommerce already pushes this way round (`twoincSoleTrader.apply()`
     * calls the equivalent chip re-sync); this is the same shape, not a new
     * mechanism.
     *
     * @returns {void}
     */
    resyncSoleTraderChip() {
        try {
            const manager = window.TwoCheckoutManager_Instance;
            const search = manager && manager.companySearch;
            if (search && typeof search.syncSoleTraderEntryVisibility === 'function') {
                search.syncSoleTraderEntryVisibility();
            }
        } catch (e) {
            // Presentation-only repaint; never let it cost the answer.
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
            // Re-stamp the existing tokens as CURRENT (round 3 adversarial
            // review finding). This is an explicit, deliberate resume - the
            // buyer clicked "Sole Trader" again - so whatever generation was
            // active when these tokens were originally minted no longer
            // matters; see the comment on `_tokensGeneration` and
            // bindPopupMessageListener() for why the stamp exists at all.
            this._tokensGeneration = this._enrollGeneration;
            // Pre-existing gap, out of scope here, widened but not caused by
            // TWO-40's OTP-trust fix (round 9/10 adversarial review
            // observation, Han + Vader): if a lookup is already in flight,
            // this silently no-ops on getCurrentBuyer()'s own guard with no
            // notifyEnrollmentSettled() of its own - this click's spinner is
            // only resolved later, incidentally, by whichever lookup IS out
            // finishing. bindPopupMessageListener() got an explicit fix for
            // the equivalent gap on its own call (`_pendingTrustedResume`);
            // this one did not, since it is not the reported bug and touches
            // a different, untrusted call path.
            this.getCurrentBuyer();
        }
    }

    /**
     * "Select a different sole trader" entry point (TWO-40 follow-up).
     * Reuses startEnrollment()'s token-minting, but deliberately SKIPS
     * getCurrentBuyer()'s silent-autofill check and goes straight to the
     * hosted signup popup - the buyer already HAS a sole-trader identity on
     * screen and is explicitly asking to replace it, so re-running the
     * same-email autofill match would just hand back the identity they are
     * trying to get away from. Covers both "pick a different registration"
     * and "register as new" - that choice happens inside the popup's own
     * UI, this plugin does not distinguish them.
     *
     * `autoselect=false` on the popup URL is not interpreted server-side
     * yet (handled elsewhere); it is appended unconditionally regardless.
     */
    startReplacement() {
        this.enrolling = true;
        this._skipAutofillCheck = true;
        if (!this.flowStarted || !this.tokens) {
            this.flowStarted = true;
            this.fetchTokens();
        } else {
            // Same "explicit resume" re-stamp as startEnrollment()'s own
            // resume branch, then straight through afterTokensReady() rather
            // than inlining its own openPopup() call (review finding,
            // TWO-40 follow-up) - one place consumes `_skipAutofillCheck`,
            // not two, so a future change to what "tokens ready" means only
            // has one call site to update.
            this._tokensGeneration = this._enrollGeneration;
            this.afterTokensReady();
        }
    }

    /**
     * Continuation once tokens are ready (a fresh mint or an existing set),
     * shared by fetchTokens()'s success branches. Ordinary flow proceeds to
     * the silent-autofill check; startReplacement() sets `_skipAutofillCheck`
     * to bypass it and open the popup directly instead. Consumed (reset)
     * here rather than left set, so a LATER ordinary startEnrollment() call
     * on the same instance is not silently affected by an earlier
     * replacement click.
     */
    afterTokensReady() {
        if (this._skipAutofillCheck) {
            this._skipAutofillCheck = false;
            this.openPopup({ autoselect: 'false' });
            return;
        }
        this.getCurrentBuyer();
    }

    /**
     * Abandon an in-progress enrolment without discarding minted tokens -
     * re-entering via startEnrollment() resumes rather than re-mints. Called
     * when the buyer goes back to ordinary company search (opening the
     * dropdown again) or when the billing country stops being eligible.
     *
     * The `enrolling` bookkeeping is a NO-OP once it is already false
     * (adversarial review finding, TWO-40). TwoCompanySearch.openDropdown()
     * calls this UNCONDITIONALLY on every open, including long after a
     * SUCCESSFUL enrolment - and without that guard, hidePrompt() would hide
     * the confirmation status showStatus() left on screen every single time
     * the buyer so much as reopens company search afterward. `enrolling`
     * flips false on success (see applyBuyer()) precisely so THAT part stays
     * a no-op past that point.
     *
     * `_enrollGeneration` is bumped UNCONDITIONALLY, deliberately outside
     * that guard (round 2 adversarial review finding). The buyer reopening
     * search and picking a REAL registered company does not discard a
     * still-in-flight sole-trader lookup - the message listener for the
     * hosted signup popup is deliberately NOT gated on `enrolling` any more
     * (see bindPopupMessageListener(), the round-1 fix for a dropped
     * genuine completion) - so a getCurrentBuyer() call issued before this
     * cancel can still resolve to applyBuyer() afterward. Without a
     * generation to check, that resolution would call
     * publishConfirmedSelection() and silently overwrite the company the
     * buyer explicitly moved on to search and select, with the order-intent
     * credit check then running against the WRONG entity and nothing on
     * screen to show it happened. See getCurrentBuyer()/applyBuyer().
     *
     * @param {boolean} [keepPopupTracked] Disown the WRITE without giving up
     *   the popup handle. For a caller that is NOT a buyer gesture and does
     *   not close the window it is walking away from -
     *   TwoCompanySearch.destroy(), see there. Default false, because every
     *   other caller IS such a gesture: the buyer has visibly moved on, so
     *   tracking a window they are no longer looking at only holds the settle
     *   back behind notifyEnrollmentSettled()'s popup-open guard.
     */
    cancelEnrollment(keepPopupTracked = false) {
        this._enrollGeneration += 1;
        // Belt-and-braces alongside afterTokensReady()'s own reset: an
        // abandoned startReplacement() must not leave this set for whatever
        // ORDINARY flow resumes next.
        this._skipAutofillCheck = false;
        if (!keepPopupTracked) {
            // The buyer moved on to a different search interaction, not the
            // popup itself closing - stop tracking it (no leaked poll interval)
            // and clear the reference so the notify below isn't held back by
            // notifyEnrollmentSettled()'s popup-open guard.
            this.stopPopupWatch();
            this._popup = null;
        }
        if (!this.enrolling) {
            return;
        }
        this.enrolling = false;
        this.hidePrompt();
        // TWO-40 round 5 (adversarial review finding): a genuine cancellation
        // - `enrolling` really was true - is itself a terminal state for
        // whichever click started the flight being cancelled, exactly like a
        // success or a failure. Without this, TwoCompanySearch.js's spinner/
        // listener only got cleared because every ONE of its own callers
        // happens to also call endSoleTraderLoading() directly in the same
        // tick - a coincidence of two files' invariants staying in lockstep,
        // not an enforced contract. See TwoCompanySearch.js's own callers of
        // cancelEnrollment(), all of which now unbind their listener BEFORE
        // calling this, so this dispatch reaching an already-unbound
        // listener there is the expected, harmless case.
        //
        // Forced past notifyEnrollmentSettled()'s write-round-trip guard: the
        // generation bump above has already disowned whatever lookup or write
        // is still in the air, so there is nothing left worth waiting for -
        // and waiting would hold a spinner up over a flow the buyer has
        // already left for a different search interaction. Under
        // `keepPopupTracked` the popup-open guard still holds it back, which is
        // the point: nothing was taken away from the buyer, so the popup's own
        // close is what settles.
        this.notifyEnrollmentSettled(true);
    }

    /**
     * The buyer is leaving the sole-trader flow: take the popup down AND
     * disown what it started, as ONE operation.
     *
     * ONE method rather than two calls every caller has to remember (Doug,
     * TWO-40 follow-up: "closure and enrolment cancelation [must be] a single
     * atomic operation, not two separate functions as now. It's just begging
     * to fail in some way."). It had already failed twice: "Enter manually"
     * closed the popup and never cancelled, so a lookup still in flight landed
     * on a company name the buyer had since typed by hand and ran the credit
     * check against the identity they walked away from; openDropdown()
     * cancelled and never closed, orphaning a window still on screen.
     *
     * closeSignupPopup() FIRST, and that ordering is the reason this pair
     * cannot be left to callers: cancelEnrollment() nulls `this._popup`, so
     * after it there is no handle left to close with.
     *
     * The one caller that wants only ONE half calls that half directly, and
     * says why - see TwoCompanySearch.destroy().
     */
    abandonEnrollment() {
        this.closeSignupPopup();
        this.cancelEnrollment();
    }

    /**
     * The actual mint POST, shared by fetchTokens() and refreshTokens()
     * (TWO-40 follow-up) so a future change to this request has one call
     * site, not two.
     *
     * Deliberately billingCountry(): that is the SAME resolver
     * isAvailableForCurrentCountry() answers the chip's visibility from, so
     * the country the mint is authorised against and the country the chip
     * was shown for cannot disagree by construction. Sent urlencoded, the
     * way applyBuyer() posts to saveCompany.
     *
     * The server prefers this posted country over anything it holds: the
     * invoice-address tier that used to outrank it was deleted, not
     * demoted, so the posted country wins outright and the cart's DELIVERY
     * address is consulted only when no usable country was posted at all.
     * It re-checks the registry either way.
     *
     * @param {string} country
     * @returns {Promise} the fetch() promise
     */
    mintTokensRequest(country) {
        const body = new URLSearchParams({ country: country });
        return fetch(this.moduleUrl('soleTraderTokens'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
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
        if (this.isFetchingTokens) {
            // A request IS already out - this click is riding it, and its
            // own resolution (the `else if (self.enrolling)` resume branch
            // below) is what settles this click, not this return.
            return;
        }
        if (Date.now() < this.nextRetryAt) {
            // Round 7 adversarial review finding (Vader): unlike the
            // isFetchingTokens branch above, NOTHING is in flight to ever
            // resume this click - the cooldown predates the round-4 "keep
            // panel open until settle" redesign and was never wired into
            // it. A click landing inside the cooldown window used to
            // dead-end with the panel/spinner stuck open indefinitely -
            // TwoCompanySearch.js's listener had nothing left to ever hear.
            // showError() also notifies (see its own comment), settling
            // THIS click exactly as a real failed mint would.
            this.showError();
            return;
        }
        this.isFetchingTokens = true;
        const self = this;
        // Captured BEFORE the request starts (round 3 adversarial review
        // finding) - a mint still outstanding when the buyer reopens search
        // and cancels must resolve into tokens correctly stamped as STALE,
        // not as current-at-resolution-time. Stamping with whatever
        // `_enrollGeneration` happens to read at the moment the mint
        // resolves would make an abandoned attempt's tokens look current
        // again the instant they land, defeating bindPopupMessageListener()'s
        // whole check.
        const generation = this._enrollGeneration;
        // Everything from here down to the fetch() call starting is
        // synchronous and, before this try (TWO-40 round 5 follow-up, Vader
        // finding round 2), unprotected: a throw anywhere in it - e.g.
        // billingCountry() or moduleUrl() reading a malformed config - left
        // `isFetchingTokens` stuck `true` forever. Every later click would
        // then silently no-op on that guard for the rest of the page's
        // life, with nothing ever in flight and no settle event to ever
        // close a THEN-open panel/spinner - the try/catch TwoCompanySearch.js
        // has around calling startEnrollment() only protects the FIRST such
        // click, not the ones after it. Treated exactly like a network
        // failure once caught.
        let request;
        try {
            request = this.mintTokensRequest(this.billingCountry());
        } catch (e) {
            this.isFetchingTokens = false;
            this.nextRetryAt = Date.now() + this.retryCooldownMs;
            this.showError();
            return;
        }
        request
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (self._destroyed) {
                    // Round 2 adversarial review (Leia): this instance is
                    // gone - nothing below is safe to act on, and arming a
                    // NEW setInterval here would leak it (destroy() already
                    // ran, so nothing will ever call stopTokenRefreshInterval()
                    // on this instance again).
                    return;
                }
                if (json && json.success && json.autofill_token) {
                    self.tokens = json;
                    // Idempotent (see its own guard) - started on the FIRST
                    // real mint, not eagerly in the constructor, since a
                    // buyer who has never touched the sole-trader flow has
                    // no tokens to keep alive (TWO-40 follow-up).
                    self.startTokenRefreshInterval();
                    // Stamp with the CAPTURED generation, not the current
                    // one - see the comment above. If cancelEnrollment() ran
                    // while this request was out, `generation` is already
                    // behind `self._enrollGeneration` and the stamp
                    // correctly reads as stale; bindPopupMessageListener()'s
                    // check (and a resumed startEnrollment()'s own re-stamp)
                    // are what bring it current again, never this callback.
                    self.bindPopupMessageListener();
                    if (self._enrollGeneration === generation) {
                        // Ordinary case: nothing has cancelled since this
                        // mint was requested. Stamp with the CAPTURED
                        // generation, not the current one - if cancelEnrollment()
                        // runs AFTER this point but before the tokens are
                        // acted on, the stamp must already read as stale.
                        self._tokensGeneration = generation;
                        self.afterTokensReady();
                    } else if (self.enrolling) {
                        // TWO-40 round 5 (adversarial review finding, Han +
                        // Yoda independently): THIS mint's own generation is
                        // stale (cancelEnrollment() ran while it was out -
                        // e.g. the buyer abandoned via "Registered Company"
                        // then clicked "Sole Trader" again before this
                        // resolved). But `fetchTokens()`'s own re-entry
                        // guard (isFetchingTokens, above) means the SECOND
                        // click's startEnrollment() call rode along on this
                        // exact request rather than firing a new one - there
                        // is only ever one mint in flight at a time. If
                        // `enrolling` is true, a later click IS still
                        // waiting on exactly these tokens; silently dropping
                        // them here (the previous behaviour) left that
                        // click's spinner and open panel with nothing left
                        // to ever settle it. Stamp and resume for whichever
                        // generation is CURRENT, not the stale one that
                        // requested the mint - mirrors startEnrollment()'s
                        // own "resume" branch below for the same tokens.
                        self._tokensGeneration = self._enrollGeneration;
                        self.afterTokensReady();
                    }
                    // Else: genuinely abandoned, nobody enrolling right now.
                    // cancelEnrollment() already notified whichever click
                    // started this mint that its wait is over; these tokens
                    // are simply kept for a future explicit resume.
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
     * Start the 30-minute background re-mint (TWO-40 follow-up, Doug: a
     * buyer who sits on checkout too long can outlast the delegated auth
     * tokens' server-side lifetime, breaking autofill and the sole-trader
     * flow entirely). Idempotent - a mint that FAILS after the interval is
     * already running (a later click retries fetchTokens(), which reaches
     * this same success branch a second time) must not arm a second,
     * duplicate interval.
     *
     * A single setInterval, not a recursive setTimeout chain: refreshTokens()
     * has no per-tick backoff to carry between calls, so there is nothing a
     * chain would buy here over a fixed 30-minute cadence.
     *
     * @returns {void}
     */
    startTokenRefreshInterval() {
        if (this._tokenRefreshIntervalId) {
            return;
        }
        const self = this;
        this._tokenRefreshIntervalId = setInterval(function () {
            self.refreshTokens();
        }, TwoSoleTrader._TOKEN_REFRESH_INTERVAL_MS);
    }

    /**
     * @returns {void}
     */
    stopTokenRefreshInterval() {
        if (this._tokenRefreshIntervalId) {
            clearInterval(this._tokenRefreshIntervalId);
            this._tokenRefreshIntervalId = null;
        }
    }

    /**
     * One periodic re-mint tick (TWO-40 follow-up). Reuses fetchTokens()'s own
     * `isFetchingTokens` guard rather than a second, uncoordinated one: if a
     * user-driven mint is already out when this fires, that mint will land
     * and refresh `this.tokens` itself - issuing a second concurrent request
     * here would break the "exactly one mint in flight" invariant fetchTokens()'s
     * own comments document at length. This tick is simply skipped; the next
     * scheduled one will try again.
     *
     * Deliberately NOT gated on `_enrollGeneration`/`_tokensGeneration`: unlike
     * fetchTokens()'s success branch, this call does not ACT on the tokens -
     * no afterTokensReady(), no popup, no getCurrentBuyer() - it only replaces
     * the token VALUES a later, generation-checked call will read. Which
     * enrolment attempt (if any) those values belong to is unaffected by
     * refreshing them, so there is nothing here for a generation check to
     * protect.
     *
     * IS gated on the open-popup and country checks below (adversarial
     * review, round 1 - Han/Vader/Yoda independently) - both protect an
     * invariant that has nothing to do with `_enrollGeneration` but that this
     * call can still break silently.
     *
     * Known residual gap (round 2/3 adversarial review, Vader finding,
     * accepted): while a popup stays open, EVERY tick is skipped, so tokens
     * baked into that popup's URL at open time are never refreshed for as
     * long as it stays open. A popup left open past the server's own token
     * TTL still hits the expiry this file exists to fix - just narrowed from
     * "always broken past 30 minutes" to "broken only if the buyer leaves
     * the popup open that long". A "re-mint the moment the popup closes"
     * fix would NOT meaningfully narrow this further: the dominant
     * completion path is the OTP 'ACCEPTED' postMessage
     * (bindPopupMessageListener() -> getCurrentBuyer()), which fires and
     * reads `this.tokens.autofill_token` BEFORE the popup close is ever
     * observed - watchPopupUntilClosed()'s poll runs after, not before,
     * that read. So the accepted trade is specifically against the
     * round-1 bug (silently authenticating against a token pair the
     * popup's own OTP flow never ran through), not a placeholder for an
     * easy popup-close-triggered fix. Not fixed here.
     *
     * Unlike fetchTokens()'s OWN failure handling, a failed refresh leaves
     * `this.tokens` exactly as it was rather than nulling it out: the existing
     * (not-yet-expired) tokens are still the buyer's best option until a
     * later tick actually replaces them, and nulling them on a transient
     * failure would break autofill immediately instead of waiting for the
     * next scheduled retry.
     *
     * @returns {void}
     */
    refreshTokens() {
        if (this.isFetchingTokens || !this.tokens) {
            return;
        }
        // A popup opened against the CURRENT `this.tokens` (openPopup() bakes
        // `delegation_token`/`autofill_token`/`signup_url` into its URL at
        // open time, not read live) is still tracked open and awaiting its
        // own 'ACCEPTED' completion. Swapping `this.tokens` under it would
        // authenticate that completion's buyer lookup
        // (bindPopupMessageListener() -> getCurrentBuyer() ->
        // `this.tokens.autofill_token`) against a pair the buyer's OTP flow
        // never actually ran through - exactly the "real auth, rejected"
        // failure class this file has hardened against elsewhere. Skip the
        // tick; watchPopupUntilClosed() clears `this._popup` once it closes,
        // letting a later tick through.
        if (this._popup && !this._popup.closed) {
            return;
        }
        // The buyer may have changed billing country since these tokens were
        // minted without the country becoming ineligible (which would have
        // routed through cancelEnrollment() instead). fetchTokens()'s own
        // mint deliberately keeps the posted country and the eligibility
        // country in agreement "by construction" (see its own comment) -
        // re-minting here for a country that no longer matches
        // `this.tokens.country` would mint fresh tokens for a country the
        // buyer's on-screen enrolment no longer belongs to. Skip the tick
        // rather than silently disagree with it.
        if (this.tokens.country && this.billingCountry() !== this.tokens.country) {
            return;
        }
        this.isFetchingTokens = true;
        const self = this;
        let request;
        try {
            // Same synchronous-throw protection as fetchTokens()'s own try -
            // billingCountry()/moduleUrl() reading a malformed config must
            // not leave `isFetchingTokens` stuck true forever. Unlike that
            // failure branch, there is no showError() here (nothing user-
            // facing to interrupt on a background tick) - but a persistent
            // throw would otherwise go completely unsignalled forever, so
            // this gets the same console.error breadcrumb as the other
            // background failure this file has no on-page UI for (see
            // openPopup()'s blocked-popup branch).
            request = this.mintTokensRequest(this.billingCountry());
        } catch (e) {
            this.isFetchingTokens = false;
            // eslint-disable-next-line no-console
            console.error('Two: background sole-trader token refresh failed to build its request.', e);
            return;
        }
        request
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (self._destroyed) {
                    // Same reasoning as fetchTokens()'s own success branch -
                    // this instance is gone, nothing below is safe to act on.
                    // Arms nothing here (unlike that branch), but writing
                    // `self.tokens` on a torn-down instance is still not a
                    // legitimate effect to have.
                    return;
                }
                // Re-checked, not just checked at entry (round 2 adversarial
                // review, Han finding): the guard above only proves no popup
                // was open when this tick STARTED. The buyer can still click
                // the on-page prompt - openPopup() has no isFetchingTokens
                // guard of its own and bakes whatever `this.tokens` holds
                // RIGHT NOW into its URL - while this mint's POST is still
                // out. Applying `json` after that would silently orphan the
                // just-opened popup's tokens exactly as the entry guard was
                // built to prevent, just through the async gap instead of a
                // stale read at tick-start.
                if (self._popup && !self._popup.closed) {
                    return;
                }
                if (json && json.success && json.autofill_token) {
                    self.tokens = json;
                }
                // Else: leave the existing tokens in place, see doc above.
            })
            .catch(function () {
                // Transport failure - leave the existing tokens in place.
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
     * Re-run getCurrentBuyer() for whichever generation is CURRENT, called
     * from getCurrentBuyer()'s own superseded branches when `enrolling` is
     * still true - i.e. a later click abandoned-then-resumed while THIS
     * lookup was outstanding, riding it via the isFetchingBuyer single-
     * flight guard rather than issuing its own (TWO-40 round 5 follow-up,
     * Han finding round 2).
     *
     * Deferred to a macrotask (`setTimeout(..., 0)`), not called directly.
     * This runs from INSIDE the `.then()`/`.catch()` handler of the request
     * that just finished - `isFetchingBuyer` is still true at that point,
     * because the `.finally()` chained after it has not run yet, and won't
     * until this handler returns. Calling getCurrentBuyer() synchronously
     * here would race that pending `.finally()`: whichever of "the resumed
     * call sets isFetchingBuyer back to true" or "the original chain's
     * finally resets it to false" runs SECOND would win, and the finally
     * runs second by construction (it is queued as this handler returns),
     * so a synchronous call was getting its own re-entrancy flag reset out
     * from under it moments after starting. Deferring to a macrotask runs
     * this after that finally has already settled the flag, so the resumed
     * call's own true/false bracketing is undisturbed.
     *
     * Assumes `this` outlives the deferred macrotask - true today only
     * because `window.TwoSoleTrader_Instance` is created exactly once
     * (views/js/twopayment.js) and never destroyed/recreated in production.
     * A future refactor that DOES tear down and rebuild the instance
     * mid-page needs to clear any pending timer from this method in its
     * own destroy(), the same way removeDropdown()/closeDropdown() already
     * clear TwoCompanySearch.js's own timers - round 3 adversarial review
     * observation (Vader), not fixed here because the precondition it
     * guards against does not exist in this codebase today.
     *
     * @param {boolean} [trustedIdentity] Carried forward from the call being
     *   resumed - see getCurrentBuyer()'s own JSDoc for what this means.
     * @param {boolean} [retriedTrustedLookup] Carried forward too (TWO-40
     *   round 9, adversarial review finding, Vader): without this, a resume
     *   landing mid-retry silently reset the retry cap to zero by calling
     *   getCurrentBuyer() with its default `false` - each abandon/resume
     *   cycle during the 800ms wait bought the flow ANOTHER retry, contrary
     *   to the "one retry, not a backoff loop" contract documented on
     *   getCurrentBuyer()'s own 404 branch.
     */
    resumeIfStillEnrolling(trustedIdentity = false, retriedTrustedLookup = false) {
        if (!this.enrolling) {
            return;
        }
        const self = this;
        setTimeout(function () {
            // Re-check at FIRE time, not just at schedule time (round 7
            // adversarial review finding, Han): the gap between scheduling
            // this macrotask and it actually running is real time in a real
            // browser, and a second abandon can land in it - a second
            // "Registered Company"/"Enter Manually" click, or another
            // country change, calling cancelEnrollment() again before this
            // fires. Without re-checking, this ran a full, unwanted lookup
            // for a buyer who had already walked away from the flow a
            // second time - on the no-match path, popping an unwanted
            // signup window.
            if (!self.enrolling) {
                return;
            }
            // Carries the original call's trust level forward (TWO-40 live
            // fix): a resume riding a call that itself followed a real OTP
            // round trip is still standing in for that same authenticated
            // buyer, not a fresh, unauthenticated heuristic probe.
            self.getCurrentBuyer(trustedIdentity, retriedTrustedLookup);
        }, 0);
    }

    /**
     * Autofill from the buyer's current Two sole-trader business.
     *
     * @param {boolean} [trustedIdentity] True only when this call follows a
     *   real OTP round trip in the hosted signup popup (bindPopupMessageListener()'s
     *   'ACCEPTED' handler, and any resume that rides that same call - see
     *   resumeIfStillEnrolling()). In that case `buyer` IS the buyer: the
     *   popup just authenticated them, by whatever email they entered
     *   THERE, which this browser never sees and has no business
     *   re-validating. Requiring it to also equal checkoutEmail() - the
     *   separate email PrestaShop's own personal-information step collected
     *   for the order - was live-bug TWO-40 (Doug, 2026-08-12): a buyer
     *   enrolled under a real sole-trader email different from the one on
     *   the order got a real, successful OTP verification for that email,
     *   then had this check silently disagree with the server and reopen
     *   the signup popup, forever. The two emails identify two different
     *   things (who authenticated vs. who the order is addressed to) and
     *   there is no requirement they match.
     *
     *   Without `trustedIdentity` (the passive paths - startEnrollment()'s
     *   initial call, fetchTokens()'s resume branches, and an UNTRUSTED
     *   resumeIfStillEnrolling()), nobody has authenticated anything yet -
     *   this is only a heuristic "does an existing Two session cookie
     *   happen to belong to the same person filling out this checkout"
     *   probe, so still requires the email match (case-insensitive) before
     *   auto-applying a stranger's data. A 404, a missing checkout email, or
     *   an email mismatch on THIS path means no usable registration yet -
     *   show the signup prompt instead.
     *
     * @param {boolean} [retriedTrustedLookup] Internal - true only on the
     *   ONE retry `trustedIdentity`'s own 404 branch schedules below. Never
     *   pass this from a new call site.
     */
    getCurrentBuyer(trustedIdentity = false, retriedTrustedLookup = false) {
        // Re-entrancy guard (TWO-40 round 5, adversarial review finding -
        // Han + Vader independently caught this): unlike fetchTokens()
        // (isFetchingTokens), this had no guard of its own before. A second
        // click while a lookup was already outstanding fired a second,
        // concurrent lookup - on the no-match path each one independently
        // reaches openPopup(), so a buyer clicking twice got TWO signup
        // popup windows from one gesture. TwoCompanySearch.js's own click
        // handler now guards on `_soleTraderLoading`, but this is kept as a
        // second, symmetric layer: bindPopupMessageListener()'s resumed
        // lookup (a genuinely separate caller, off a real user action in
        // another window) goes through here too, and should not be able to
        // race a lookup already running for the same reason.
        if (this.isFetchingBuyer) {
            return;
        }
        this.isFetchingBuyer = true;
        const self = this;
        // Captured BEFORE the request starts (round 2 adversarial review
        // finding). cancelEnrollment() bumps this on every call, including
        // one triggered by the buyer reopening search and picking a
        // DIFFERENT, real company while this request is still out - see
        // cancelEnrollment()'s own comment. Every continuation below checks
        // it before touching anything, not only the publish path in
        // applyBuyer(): a stale prompt/error appearing after the buyer has
        // moved on is confusing even where it is not a data-integrity risk.
        const generation = this._enrollGeneration;
        const superseded = function () {
            return self._enrollGeneration !== generation;
        };
        // Set true by the 404-retry branch below, and read by `.finally()`
        // (TWO-40 round 9, adversarial review finding, Han + Vader): a
        // `setTimeout` scheduled from inside a `.then()` handler is a bare
        // side effect, not something the promise chain awaits - returning
        // from that handler settles the promise immediately, so the chained
        // `.finally()` fires right away too, ~800ms BEFORE the retry
        // actually runs. Releasing `isFetchingBuyer` there reopens the exact
        // concurrent-lookup window the round-5 guard exists to close, for
        // the whole retry wait. `.finally()` below checks this flag and, if
        // set, leaves the guard alone - `settle()` releases it instead,
        // called from the retry's own callback right before it decides what
        // to do, the same moment it would have been released for an
        // ordinary (non-retry) request.
        let retryScheduled = false;
        // @returns {boolean} true if a pending resume was consumed and
        //   re-issued - the caller must not ALSO act on its own terms in
        //   that case (round 9 follow-up: the retry's own callback below
        //   used to check `isFetchingBuyer` AFTER calling this to decide
        //   whether to defer, but that flag is exactly what THIS call just
        //   set when it fired the resume, so the check always read "busy"
        //   because of its own action, not someone else's, and queued a
        //   second, redundant resume that fired again once the first one's
        //   own request finished - a self-inflicted double buyer lookup for
        //   one authentication event. JS is single-threaded and everything
        //   here runs synchronously, so if a resume WASN'T fired, nothing
        //   else could have raced `isFetchingBuyer` true in the meantime
        //   either - the caller does not need to check it itself).
        const settle = function () {
            self.isFetchingBuyer = false;
            // A genuine 'ACCEPTED' that arrived while THIS request (or its
            // retry) was still out set this flag instead of issuing its own
            // call - see bindPopupMessageListener(). Re-issue it now, fresh,
            // for whichever generation is CURRENT.
            if (self._pendingTrustedResume) {
                self._pendingTrustedResume = false;
                if (self._enrollGeneration === self._tokensGeneration) {
                    self.getCurrentBuyer(true);
                    return true;
                }
            }
            // AFTER the resume above, not before it: a resume re-holds the
            // guard for a lookup that has not answered yet, and releasing a
            // deferred settle in between would end the spinner in the middle
            // of the very round trip it is waiting on.
            self.flushDeferredSettle();
            return false;
        };
        // Same reasoning as fetchTokens()'s own try/catch around its
        // pre-fetch setup (TWO-40 round 5 follow-up, Vader finding round 2):
        // `this.tokens.autofill_token` below throws synchronously if
        // `this.tokens` is ever null when this runs, and nothing before
        // this fix protected `isFetchingBuyer` against that - a stuck-true
        // guard here silently no-ops every future click for the rest of
        // the page's life, the exact failure mode this whole PR chain
        // exists to close.
        let request;
        try {
            request = fetch(TwoSoleTrader.withTwoClientParams(this.config.checkoutHost + '/autofill/v1/buyer/current'), {
                credentials: 'include',
                headers: { 'two-delegated-authority-token': this.tokens.autofill_token }
            });
        } catch (e) {
            this.isFetchingBuyer = false;
            this.showError();
            return;
        }
        request
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
                if (superseded()) {
                    // TWO-40 round 5 follow-up (Han finding, round 2): the
                    // SAME abandon-then-retry shape fetchTokens()'s success
                    // branch was fixed for above, one stage deeper. This
                    // lookup's own single-flight guard (isFetchingBuyer)
                    // means a click that arrived while this request was
                    // already out never issued its own lookup - it is riding
                    // this one. If this generation is now stale but
                    // something is still enrolling, re-run the lookup for
                    // whichever generation IS current rather than dropping
                    // this result on the floor with nothing left to ever
                    // settle the resumed click's spinner. Deliberately a
                    // fresh getCurrentBuyer() call (not just falling through
                    // with the stale `buyer`/`generation` closures) - a
                    // buyer lookup, unlike a token mint, must be re-run for
                    // the current identity/generation, not replayed.
                    self.resumeIfStillEnrolling(trustedIdentity, retriedTrustedLookup);
                    return;
                }
                if (trustedIdentity && !buyer && !retriedTrustedLookup) {
                    // Round 8 adversarial review finding (Han + Vader
                    // independently): a 404 here right after a genuine
                    // 'ACCEPTED' is read-after-write lag, not "not
                    // registered" - the popup's own OTP round trip just
                    // completed server-side, and this GET can briefly not
                    // see it yet. Without this, that ordinary race fell
                    // straight into showPrompt()/openPopup() below,
                    // reopening the exact popup the buyer had just finished
                    // with - the same reported symptom (real auth, rejected,
                    // looped), just moved from a guaranteed email mismatch to
                    // an occasional timing race. One retry, after a short
                    // delay, before treating "not visible yet" the same as
                    // "no registration exists". `retriedTrustedLookup` caps
                    // it at exactly one - this is not a backoff loop, and
                    // `resumeIfStillEnrolling()` now forwards it too (round 9
                    // finding, Vader) - without that, a resume landing
                    // mid-wait bought the flow another retry, resetting the
                    // cap to zero every abandon/resume cycle.
                    retryScheduled = true;
                    // Not cleared by destroy() - same accepted risk as
                    // resumeIfStillEnrolling()'s own deferred macrotask (see
                    // its JSDoc): `window.TwoSoleTrader_Instance` is created
                    // once and never torn down in production today, so this
                    // firing against a destroyed instance is not a real
                    // precondition yet (round 10 adversarial review
                    // observation, Han).
                    setTimeout(function () {
                        // The guard was deliberately left held for this
                        // entire wait, not released back in `.finally()` -
                        // see `settle()`'s own comment above. Release it now,
                        // right before deciding what to do - the same moment
                        // it would have been released for an ordinary
                        // (non-retry) request.
                        if (settle()) {
                            // A pending trusted resume was waiting (a SECOND
                            // 'ACCEPTED' that arrived during this wait, found
                            // the guard held, and flagged itself instead of
                            // firing its own lookup - see
                            // bindPopupMessageListener()). settle() just
                            // re-issued it fresh; that already stands in for
                            // THIS retry, so do not also fire a second,
                            // concurrent lookup on top of it (round 9
                            // follow-up finding, self-caught: the earlier
                            // shape of this check queued a redundant resume
                            // for its own action, not someone else's).
                            return;
                        }
                        if (!self.enrolling) {
                            // Already settled by another lookup while this
                            // retry was waiting (success, error, or a genuine
                            // cancel) - mirrors resumeIfStillEnrolling()'s own
                            // re-check (round 9 finding, Han + Vader);
                            // nothing left to retry for.
                            return;
                        }
                        if (superseded()) {
                            self.resumeIfStillEnrolling(trustedIdentity, true);
                            return;
                        }
                        self.getCurrentBuyer(trustedIdentity, true);
                    }, 800);
                    return;
                }
                // `trustedIdentity` skips the email-match heuristic entirely:
                // the buyer just authenticated in the hosted signup popup, so
                // `buyer` (if present) IS them, whatever email PrestaShop's
                // own checkout form happens to hold. See the JSDoc above.
                const entered = self.checkoutEmail().trim().toLowerCase();
                const matches = trustedIdentity
                    ? !!buyer
                    : !!(buyer && buyer.email && entered
                        && String(buyer.email).toLowerCase() === entered);
                if (matches) {
                    self.applyBuyer(buyer, generation);
                } else if (self.container() && self.container().querySelector('.two-sole-trader__prompt')) {
                    self.showPrompt();
                    // No error, but nothing left for this click's own round
                    // trip to wait on - the flow now waits on the buyer
                    // clicking the on-page prompt, a separate user action.
                    self.notifyEnrollmentSettled();
                } else {
                    // TWO-40 follow-up: on the address-editor page there is no
                    // `.two-sole-trader` container at all (it is only rendered
                    // by the payment-step template, paymentinfo.tpl), so
                    // showPrompt()'s querySelector always comes back null and
                    // the buyer's chip click dead-ends silently - tokens get
                    // minted, this lookup fires, and nothing else happens.
                    // Empirically verified in real Chrome against staging
                    // (chained fetch()->fetch()->window.open() off a real
                    // click survives Chrome's transient-activation window
                    // under normal latency) that opening the popup directly
                    // here, still async off the original click, is not
                    // blocked in practice. Payment-step keeps the two-click
                    // showPrompt()->openPopup() flow unchanged since its
                    // container/prompt element exists there. openPopup()
                    // itself notifies from both of its own branches (opened
                    // fine, or blocked) - see there.
                    self.openPopup();
                }
            })
            .catch(function () {
                if (superseded()) {
                    // Same reasoning as the success branch above (round 5
                    // follow-up, Han finding round 2): a resumed click may
                    // be riding this exact request. A network failure on it
                    // is a real failure for that resumed click too, not just
                    // for the stale one that originally issued it - retry
                    // once for the current generation rather than dropping
                    // it silently. (The retry itself can fail again, but
                    // that failure will correctly reach showError()/notify
                    // for the then-current generation on its own terms.)
                    self.resumeIfStillEnrolling(trustedIdentity, retriedTrustedLookup);
                    return;
                }
                self.showError();
            })
            .finally(function () {
                if (retryScheduled) {
                    // Guard intentionally left held - see `settle()` and the
                    // retry's own setTimeout callback above, which releases
                    // it right before firing, not now (round 9 adversarial
                    // review finding, Han + Vader).
                    return;
                }
                settle();
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
     *  - the country MUST be the one the token response reports, not a DOM
     *    guess. That value is whichever tier the server resolved the mint
     *    against (the cart's invoice address, the posted country, or the
     *    cart's delivery address) - and it is the only country this browser
     *    can know the mint was actually authorised for.
     *    getTwoValidatedSessionCompanyData() wipes the whole session company
     *    the moment the saved country disagrees with the cart's own
     *    invoice-address country, so guessing here loses the enrolment.
     */
    /**
     * @param {Object} buyer
     * @param {number} generation the value of `_enrollGeneration` at the
     *   moment the getCurrentBuyer() call that led here was ISSUED (round 2
     *   adversarial review finding). Re-checked again below, after this
     *   method's own `saveCompany` round trip - that request is itself async
     *   and long enough for the buyer to reopen search and cancel (or start a
     *   fresh enrolment) while it is in flight, and a superseded save
     *   response must not publish over whatever they have since done.
     */
    applyBuyer(buyer, generation) {
        const self = this;
        // Held for this whole chain so the flight cannot settle - and the
        // buyer's spinner cannot stop - before the company name and number
        // are actually in the form. Released in `.finally()` below, which
        // covers the superseded early return and showError() as well as the
        // success branch. See notifyEnrollmentSettled().
        this._pendingAdoptionWrites += 1;
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
                if (self._enrollGeneration !== generation) {
                    // Superseded while this request was out. The server HAS
                    // been told to persist it either way (see the comment
                    // above applyBuyer() about the session cookie needing
                    // this for the OPPOSITE ordering) - only the in-memory
                    // publish and the on-screen status are skipped, so this
                    // does not fight whatever the buyer has done since.
                    return;
                }
                if (json && json.success) {
                    // Put the enrolled identity INTO the form the buyer is
                    // looking at - company name, organisation number, registered
                    // address.
                    //
                    // Ordered before the publish below because the adoption may
                    // DISOWN a previous selection (the blank-`company_name` branch
                    // clears the stale hidden pair), and the publish is what
                    // establishes the selection that must survive. Running the
                    // publish first would have the clear tear down the pair that had
                    // just been published. It is NOT ordered this way so that
                    // setConfirmedCompanySelection() can see the written address:
                    // that method captures only the selected address id and country
                    // iso, never any address value.
                    //
                    // Delegated rather than reimplemented here. Every write into
                    // the address form has to be attributable by the same
                    // machinery that judges it later - the `data-two-company-name`
                    // pairing tag, the `data-two-autofilled-value` marker, and the
                    // cart-scoped mirror-write record - and all three live in
                    // TwoCompanySearch. Three earlier attempts at this write-back
                    // were withdrawn for hand-rolling it here instead
                    // (`.ai/decisions.md`, 2026-08-10); see adoptSoleTraderBuyer().
                    self.adoptEnrolledIdentity(buyer);
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
                    self.showStatus(window.TwoCompanyNumber.forDisplay(companyLabel) || self.config.statusLabel || 'Sole trader');
                    self.hidePrompt();
                    // Enrolment is DONE, not merely inactive (adversarial review
                    // finding, TWO-40) - reopening company search later must not
                    // hide this status. See cancelEnrollment()'s no-op guard.
                    self.enrolling = false;
                    self.stopObserving();
                    document.dispatchEvent(new CustomEvent('two:sole-trader-ready'));
                    self.notifyEnrollmentSettled();
                } else {
                    self.showError();
                }
            })
            .catch(function () {
                self.showError();
            })
            .finally(function () {
                self._pendingAdoptionWrites -= 1;
                self.flushDeferredSettle();
            });
    }

    /**
     * Write the enrolled sole trader's data into the checkout form, through
     * TwoCompanySearch (TWO-40).
     *
     * Resolved LAZILY, at call time, and that is required rather than tidy: the
     * manager destroys and rebuilds its search instance on every
     * `updatedAddressForm`, so a reference captured when this module was
     * constructed would be to an instance that no longer owns any field on the
     * page. The enrolment spans a popup round trip, which is easily long enough
     * for one of those rebuilds.
     *
     * Fails SOFT, on purpose. The write is what the buyer sees; the order itself
     * does not depend on it, because the identity also reaches the payload through
     * the session record `saveCompany` has just written and the selection published
     * beside this call. A missing search instance must therefore cost the fill, not
     * the enrolment.
     *
     * @param {Object} buyer the `/autofill/v1/buyer/current` response
     * @returns {boolean} whether anything was written
     */
    adoptEnrolledIdentity(buyer) {
        try {
            const manager = window.TwoCheckoutManager_Instance;
            const search = manager && manager.companySearch;
            if (!search || typeof search.adoptSoleTraderBuyer !== 'function') {
                return false;
            }
            return search.adoptSoleTraderBuyer(buyer);
        } catch (e) {
            return false;
        }
    }

    /**
     * Publish a confirmed company/organisation-number pair to
     * TwoCheckoutManager, which is what the order-intent payload is built from
     * (TWO-25326 bug 8). Mirror of TwoCompanySearch.publishConfirmedSelection()
     * - the two modules are the only two places a company is captured, and they
     * must feed the same store or the intent check can be built for the entity
     * the buyer is NOT.
     *
     * Also stamps `_tileCompanySelected` (TWO-40 follow-up, live bug reported
     * by Doug 2026-08-12: an order-intent check fired off this completion
     * before the buyer had reached the payment step). TwoCompanySearch's
     * onCompanySelected() stamps this same flag the instant a search RESULT is
     * picked, and TwoCheckoutManager.canAutoTriggerOrderIntent() reads it as
     * "the buyer has made their choice" before letting a generic
     * mounted/re-rendered/periodic signal auto-fire a check in TILE mode -
     * without it, a completed sole-trader enrolment was the one confirmed
     * identity that never told that gate a real choice had actually been
     * made. Harmless where the flag is not consulted (address mode; see
     * canAutoTriggerOrderIntent()'s own doc on why address mode does not
     * gate on it) and required where it is (tile mode).
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
            manager._tileCompanySelected = true;
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

    /**
     * @param {Object} [extraParams] additional query params appended to the
     *   popup URL verbatim (encoded key/value pairs), e.g. `autoselect`.
     */
    openPopup(extraParams) {
        if (!this.tokens) {
            return null;
        }
        // Round trip already handed off to a popup that is still open
        // (adversarial review finding, Han + Vader independently) - opening a
        // SECOND window here would orphan the first one, untracked:
        // `this._popup` would move to the new popup and the poll would never
        // learn the first window even existed, so a buyer who closes THAT one
        // instead of the new one would leave the spinner stuck forever. Focus
        // the existing popup instead.
        //
        // Gated on LIVENESS, not on whether the raise succeeded (round 2
        // adversarial review finding). focusSignupPopup() answers "did I raise
        // it?", which is what the Sole trader chip needs and NOT what this
        // branch needs: it reports false for a focus() that threw, and
        // returning false here would fall through and open the second window
        // this guard exists to prevent. A window we hold and that is not
        // `closed` must never be opened over, whether or not it can be raised.
        if (this._popup && !this._popup.closed) {
            this.focusSignupPopup();
            return this._popup;
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
        // PORTING NOTE (future Magento/WooCommerce port of this sole-trader
        // flow): brand resolution here is PrestaShop-only and has NO brand
        // dimension. `this.tokens.signup_url` comes from
        // TwoSoleTrader::getSignupPageUrl() (classes/TwoSoleTrader.php - see
        // its `$signup_hosts` property and the method itself; deliberately
        // not citing line numbers here, they drift), which maps ONLY
        // environment -> host - it does not know about brand overlays at all.
        // Magento/WooCommerce resolve brand via a per-brand hostname template
        // with a query-string fallback used ONLY on shared non-prod domains.
        // Porting this popup-launch URL construction to those platforms must
        // go through THAT existing mechanism, not this environment-keyed host
        // map - PrestaShop has no brand-overlay support today and this file
        // must not invent one.
        const url =
            this.tokens.signup_url +
            '?businessToken=' + encodeURIComponent(this.tokens.delegation_token) +
            '&autofillToken=' + encodeURIComponent(this.tokens.autofill_token) +
            '&autofillData=' + encodeURIComponent(this.encodeAutofillData(prefill)) +
            (extraParams && typeof extraParams === 'object'
                ? Object.keys(extraParams).map(function (key) {
                    return '&' + encodeURIComponent(key) + '=' + encodeURIComponent(extraParams[key]);
                }).join('')
                : '');
        const popup = window.open(
            url,
            '_blank',
            'location=yes,resizable=yes,scrollbars=yes,status=yes,height=805,width=700'
        );
        if (!popup) {
            // Popup blocked despite opening from a click - surface it
            // rather than failing silently. showError() itself no-ops
            // without a `.two-sole-trader__error` element (containerless
            // address-page path, TWO-40 follow-up) - console.error is the
            // only signal left there, so it is not a completely silent
            // dead end even in that edge case. showError() also notifies
            // (TWO-40 round 4) - do not ALSO notify below, or a blocked
            // popup would fire the settle event twice for one click.
            this.showError();
            if (!this.container()) {
                // eslint-disable-next-line no-console
                console.error('Two: sole-trader signup popup was blocked and no on-page error UI is available here.');
            }
        } else {
            // Opened fine - hand off to the popup window, but do NOT settle
            // the spinner yet (TWO-40 follow-up, Doug: it was clearing as
            // soon as this line ran, not when the popup actually closed).
            // watchPopupUntilClosed() settles it once `popup.closed` is
            // actually true.
            this._popup = popup;
            this.watchPopupUntilClosed();
        }
        return popup;
    }

    /**
     * Poll the popup handle until the buyer actually closes it - completes
     * the signup, cancels inside it, or just closes the window. A
     * cross-origin popup fires no event for any of those, so polling
     * `.closed` is the only reliable signal; 500ms is frequent enough to
     * feel immediate without hammering the main thread.
     *
     * Deliberately not "settled by the postMessage 'ACCEPTED' handler
     * instead" - that message means the buyer authenticated, not that the
     * popup has gone away, and the spinner must track the popup's own
     * lifetime (see notifyEnrollmentSettled()'s guard).
     */
    watchPopupUntilClosed() {
        this.stopPopupWatch();
        const self = this;
        this._popupPollInterval = window.setInterval(function () {
            if (!self._popup || self._popup.closed) {
                self._popup = null;
                self.stopPopupWatch();
                self.notifyEnrollmentSettled();
            }
        }, 500);
    }

    /**
     * Clear the popup-close poll, if one is running, without touching
     * `this._popup` - callers that already know the popup is gone clear that
     * themselves first (see watchPopupUntilClosed()); callers tearing down a
     * still-open popup's tracking (cancelEnrollment(), destroy()) clear it
     * right after calling this. Idempotent, and every exit path calls it so
     * no interval outlives its popup.
     */
    stopPopupWatch() {
        if (this._popupPollInterval) {
            window.clearInterval(this._popupPollInterval);
            this._popupPollInterval = null;
        }
    }

    /**
     * Close the hosted signup popup, if one is still up.
     *
     * Still a separate method from cancelEnrollment() rather than folded INTO
     * it, because cancelEnrollment() has one caller that must NOT close a
     * popup (TwoCompanySearch.destroy(), see there). What no longer exists is a
     * caller that wants BOTH and has to remember to say so twice:
     * abandonEnrollment() is that pair, and is what "the buyer is leaving this
     * flow" calls.
     *
     * `close()` is ours to call however cross-origin the popup's document is -
     * we are the opener - and it is a no-op on a window that has already gone,
     * which covers a buyer who closed it by hand and the hosted flow closing
     * itself the moment it posted 'ACCEPTED'. `this._popup` is deliberately
     * left for watchPopupUntilClosed()'s poll to clear, so the settle event
     * still has exactly one owner.
     *
     * @returns {void}
     */
    closeSignupPopup() {
        if (this._popup && !this._popup.closed) {
            this._popup.close();
        }
    }

    /**
     * Raise the hosted signup popup back to the front, if one is still up.
     *
     * The counterpart to closeSignupPopup() for the ONE gesture that means
     * "I want that popup, not this page" (Doug, TWO-40 follow-up): re-clicking
     * the Sole trader chip while a popup from an earlier launch is still open.
     * Every other way focus comes back to the checkout takes the popup down.
     *
     * `focus()` is wrapped because `closed` can flip between the check and the
     * call - the hosted flow closes its own window the moment it has posted
     * 'ACCEPTED', so a buyer completing signup in the same instant as this
     * runs is a real interleaving, not a theoretical one. There is nothing to
     * raise in that case and nothing to report either: the popup is going
     * away for the right reason, and watchPopupUntilClosed()'s poll still owns
     * clearing the handle and dispatching the settle.
     *
     * Raising a popup RE-ADOPTS it, which is why the tokens are re-stamped as
     * current here (adversarial review round 2). "I want that popup" is exactly
     * the explicit resume that startEnrollment()/startReplacement() re-stamp
     * for, and without it a raise is the one way back into a popup that leaves
     * `_tokensGeneration` behind - so the buyer's completion arrives and
     * bindPopupMessageListener() drops it on the generation check, silently.
     * That became reachable when destroy() started keeping a live popup across
     * an instance rebuild: the cancel bumps the generation, the raise is the
     * only thing the buyer does next, and nothing else would ever re-stamp it.
     * A no-op on every other path here, which reaches this with the two
     * generations already in agreement.
     *
     * @returns {boolean} whether a popup was actually there to raise, so a
     *   caller can tell "brought it to the front" from "no popup open" and
     *   pick a different behaviour for the latter.
     */
    focusSignupPopup() {
        if (!this._popup || this._popup.closed) {
            return false;
        }
        try {
            this._popup.focus();
        } catch (e) {
            return false;
        }
        this._tokensGeneration = this._enrollGeneration;
        return true;
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
        // Held on the instance, same reasoning as `_countryChangeHandler`,
        // so destroy() can detach it (found chasing test isolation for the
        // round-3 generation-guard tests, which is exactly the shape of leak
        // this would cause on a real page too - a destroyed instance's
        // listener would go on reacting to a LATER instance's popup, on the
        // rare page that ever constructs a second one).
        this._messageHandler = function (event) {
            // Deliberately NOT gated on `self.enrolling` (round 1 adversarial
            // review finding, TWO-40). The buyer can click back into company
            // search - which calls cancelEnrollment() unconditionally on
            // every open, see TwoCompanySearch.openDropdown() - while the
            // hosted signup popup is still open in another window; that is
            // "still glancing around", not "abandoned the flow", and must
            // not make a genuine completion silently vanish.
            if (!self.tokens) {
                return;
            }
            if (event.origin !== new URL(self.tokens.signup_url).origin) {
                return;
            }
            if (event.data !== 'ACCEPTED') {
                return;
            }
            // Gated on the STAMP, not on `enrolling` (round 3 adversarial
            // review finding). cancelEnrollment() bumps `_enrollGeneration`
            // but deliberately does not close the popup or invalidate
            // `tokens` - the flow is resumable - so this popup can still be
            // open and go on to complete long after the buyer has walked
            // away and picked a different, real company via search. Without
            // this check, `getCurrentBuyer()` would capture the CURRENT
            // (already-moved-on) generation fresh at the moment this message
            // arrives, which reads as a brand-new legitimate attempt however
            // stale the tokens actually are - silently overwriting the real
            // selection the buyer made in between. `_tokensGeneration` is
            // only re-stamped as current by an EXPLICIT resume
            // (fetchTokens()'s success handler, or startEnrollment() calling
            // back into an existing token set) - never by this listener
            // itself - so a stale popup finishing on its own has no way to
            // pass this check.
            if (self._enrollGeneration !== self._tokensGeneration) {
                return;
            }
            self.enrolling = true;
            if (self.isFetchingBuyer) {
                // Round 8 adversarial review finding (Han): a lookup for a
                // DIFFERENT call - an untrusted passive probe, or an earlier
                // resumed click - is already out. getCurrentBuyer(true)
                // would just no-op on its own re-entrancy guard below,
                // silently dropping this genuine authentication event; the
                // busy call would go on to resolve under whatever trust
                // level IT started with (untrusted), which can fail the
                // email-match heuristic and reproduce this exact bug via a
                // race instead of a guaranteed mismatch. Flag it instead -
                // the busy call's own .finally() re-issues a trusted lookup
                // once it clears, however it resolved.
                self._pendingTrustedResume = true;
                return;
            }
            // `trustedIdentity = true`: this message IS the buyer completing
            // a real OTP verification in the hosted popup. The resulting
            // buyer lookup must not be re-gated on checkoutEmail() matching -
            // see getCurrentBuyer()'s JSDoc (live bug TWO-40, Doug
            // 2026-08-12: a buyer whose Two account email genuinely differs
            // from the order's checkout email got a successful OTP, then had
            // this exact call disagree with the server and reopen the popup
            // forever).
            self.getCurrentBuyer(true);
        };
        window.addEventListener('message', this._messageHandler);
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
        // Every showError() call site is the LAST thing its branch does - a
        // terminal state for whichever round trip led here (token mint,
        // buyer lookup, or a blocked popup) - so this single spot covers
        // every failure exit without a notify at each call site. See
        // notifyEnrollmentSettled() for what this is for.
        this.notifyEnrollmentSettled();
    }

    /**
     * Tell TwoCompanySearch.js's in-flight spinner (TWO-40 round 4) that
     * THIS click's own round trip has reached a terminal state, whatever
     * that state is - a completed autofill, a signup prompt/popup handed
     * off to, or a failure. Fired from every terminal branch of
     * startEnrollment()'s call graph:
     *  - showError() (covers both fetchTokens() failure paths, the
     *    getCurrentBuyer() catch, and openPopup()'s popup-blocked branch)
     *  - getCurrentBuyer()'s showPrompt() branch (no error, but nothing left
     *    for the spinner to wait on - the flow now waits on the buyer
     *    clicking the on-page prompt)
     *  - applyBuyer()'s success branch
     *  - watchPopupUntilClosed()'s poll, once a popup that WAS opened has
     *    actually closed
     *
     * Deliberately NOT gated on `_enrollGeneration`: TwoCompanySearch.js's
     * listener is bound fresh per click and unbound on its own next close,
     * so a stale event from an abandoned attempt finding no listener left
     * to hear it is already a no-op, the same way a stale popup message
     * finding this object gone would be.
     *
     * Gated on `this._popup`, though (TWO-40 follow-up, Doug): while a
     * signup popup is open, the spinner must wait for the popup itself to
     * close - completed, cancelled inside it, or just closed by the buyer -
     * not for whichever internal call happens to settle first. applyBuyer()'s
     * success branch and showError() both still call this directly on every
     * one of their own terminal paths; this guard is what makes those calls
     * defer to watchPopupUntilClosed()'s poll instead of firing early
     * whenever a popup is the thing still open. cancelEnrollment() clears
     * `this._popup` itself first, since that path means the buyer moved on
     * to a different search interaction, not the popup closing - except
     * under its `keepPopupTracked` caller, which relies on this guard
     * holding the settle back until the popup it left open closes.
     *
     * Gated on the post-popup WRITE too (Doug, TWO-40 follow-up: "the flow is
     * complete when the popup is gone AND the lookup has come back AND the
     * name and number are saved"). The popup poll fires within 500ms of the
     * window closing, and the hosted flow closes it as soon as it has posted
     * 'ACCEPTED' - so the poll routinely won this race against the
     * getCurrentBuyer() -> saveCompany -> adoptEnrolledIdentity() chain that
     * message starts, and settled the flight with the company field still
     * empty. isWriteRoundTripOutstanding() covers that chain; whichever of
     * the two finishes last is the one that dispatches.
     *
     * @param {boolean} [force] Dispatch even with a write round trip out.
     *   For cancelEnrollment() only: the generation bump it just made means
     *   that write can no longer publish anything, so waiting for it would
     *   only leave the spinner up for a flow the buyer has already left.
     */
    notifyEnrollmentSettled(force = false) {
        if (this._popup && !this._popup.closed) {
            return;
        }
        if (!force && this.isWriteRoundTripOutstanding()) {
            this._settleDeferred = true;
            return;
        }
        this._settleDeferred = false;
        document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'));
    }

    /**
     * @returns {boolean} whether a round trip that could still write the
     *   company name/number into the form is outstanding - the buyer lookup
     *   itself (`isFetchingBuyer`, which is deliberately held across the
     *   read-after-write retry wait too, see getCurrentBuyer()) or an
     *   applyBuyer() write-back it led to.
     */
    isWriteRoundTripOutstanding() {
        return this.isFetchingBuyer || this._pendingAdoptionWrites > 0;
    }

    /**
     * Re-fire a settle that isWriteRoundTripOutstanding() held back.
     *
     * Re-checks the predicate itself rather than trusting its callers, which
     * is what lets it be called from every point one of those flags drops
     * without each caller reasoning about the others - notably
     * getCurrentBuyer()'s `settle()`, which may immediately re-issue a
     * lookup for a pending trusted resume and so must NOT release the settle
     * it is about to make outstanding again.
     */
    flushDeferredSettle() {
        if (!this._settleDeferred || this.isWriteRoundTripOutstanding()) {
            return;
        }
        this.notifyEnrollmentSettled();
    }
}

window.TwoSoleTrader = TwoSoleTrader;
