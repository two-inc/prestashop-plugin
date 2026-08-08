/**
 * TWO-25326 bug 9, availability-cache half. TWO-40 removed the "Registered
 * business" / "Sole trader" chips this test used to watch flicker across a
 * payment-fragment replacement - there is no chip UI left to flicker. What
 * survives, and what this file now covers, is the underlying availability
 * cache TwoCompanySearch.js's "I'm a sole trader" row reads via
 * isAvailableForCurrentCountry(): it must settle correctly across the same
 * container replacements and request storms that used to make the chips
 * disappear and reappear.
 *
 * WHY THE EXISTING TEST DID NOT CATCH THE ORIGINAL BUG
 * checkout-manager-render-loop.test.js covers TwoCheckoutManager's tile-level
 * mount/unmount guard - that handleDynamicContentChange() must not re-attach
 * document-level payment listeners on every debounced MutationObserver firing.
 * That fix was real, and it is not this: the availability cache is owned by a
 * DIFFERENT module (TwoSoleTrader) with a refresh/observe cycle entirely of its
 * own, which that test never loads.
 *
 * TWO ROOT CAUSES, both in TwoSoleTrader:
 *
 *  1. "Settled" was recorded as a COUNTRY (`renderedForCountry`), not as an
 *     adopted container. PrestaShop replaces the payment fragment - and with it
 *     the whole `.two-sole-trader` container - repeatedly while the step
 *     settles. The replacement arrives with no answer adopted into it, and the
 *     observer's callback then read the country as unchanged and returned
 *     early. The cache was answering for a container that no longer existed.
 *
 *  2. There was no in-flight guard on the availability request. The observer
 *     watches the whole body subtree while nothing is cached yet, so every
 *     mutation started ANOTHER `fetch`. Beyond the request storm, that made
 *     the cached answer a race between those responses: the endpoint is
 *     fail-soft to "not available", so one failure among a dozen duplicates
 *     could overwrite an answer another had just applied.
 */

'use strict';

const { loadSoleTrader, buildPaymentTile, flushPromises } = require('./ps-harness');

let TwoSoleTrader;
let fetchCalls;
let answer;

function container() {
    return document.querySelector('.two-sole-trader');
}

/**
 * Replace the `.two-sole-trader` container with a fresh copy of the template's
 * markup, exactly as a payment-fragment re-render does: same selector, new node,
 * no answer adopted, no inline state.
 *
 * @returns {HTMLElement} the new container
 */
function replaceContainer() {
    const old = container();
    const fresh = document.createElement('div');
    fresh.className = 'two-sole-trader';
    fresh.innerHTML = '<a href="#" class="two-sole-trader__prompt" style="display: none;"></a>'
        + '<span class="two-sole-trader__status" style="display: none;"></span>'
        + '<span class="two-sole-trader__error" style="display: none;"></span>';
    old.parentNode.replaceChild(fresh, old);
    return fresh;
}

/** Country select the module reads, appended outside the tile. */
function buildCountry(iso) {
    const holder = document.createElement('div');
    holder.innerHTML = "<select name='id_country'>"
        + '<option value="17" data-iso-code="' + iso + '" selected>Country</option>'
        + '</select>';
    document.body.appendChild(holder);
}

/** Let the debounced observer callback run. */
async function settle() {
    jest.advanceTimersByTime(150);
    await flushPromises();
    // A second pass: applying an answer mutates the DOM, which re-arms the
    // observer's debounce. Draining it is what proves the cycle terminates.
    jest.advanceTimersByTime(150);
    await flushPromises();
}

function build() {
    return new TwoSoleTrader({
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        shopCountry: 'NO'
    });
}

beforeEach(() => {
    jest.useFakeTimers();
    delete global.window.TwoSoleTrader;
    fetchCalls = [];
    answer = { available: true, reject: false };
    global.window.fetch = (url) => {
        fetchCalls.push(url);
        if (answer.reject) {
            return Promise.reject(new Error('network'));
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true, available: answer.available }) });
    };
    global.fetch = global.window.fetch;
    buildPaymentTile();
    buildCountry('NO');
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    if (global.window.TwoSoleTrader_Instance) {
        delete global.window.TwoSoleTrader_Instance;
    }
    jest.useRealTimers();
    document.body.innerHTML = '';
    delete global.window.fetch;
    delete global.fetch;
    // TWO-40 follow-up: the persistent availability cache is real
    // cross-test-case state now (localStorage, not the instance) - without
    // clearing it a later test's fetch assertions would silently see a hit
    // from an earlier test's write instead of the network call under test.
    global.window.localStorage.clear();
});

describe('the availability cache survives a payment-fragment replacement', () => {
    test('a replaced container gets its availability re-adopted', async () => {
        const instance = build();
        await settle();
        expect(instance.isAvailableForCurrentCountry()).toBe(true);

        // PrestaShop re-renders the payment step. Same country, brand new node.
        replaceContainer();

        await settle();

        // Before the fix this stayed stuck on the previous container's answer:
        // the country was unchanged, so the settled-check returned early and
        // the new node's own answer was never adopted.
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.stopObserving();
    });

    test('a replaced container is re-adopted as unavailable when the country is not eligible', async () => {
        answer.available = false;
        const instance = build();
        await settle();
        expect(instance.isAvailableForCurrentCountry()).toBe(false);

        replaceContainer();
        await settle();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        instance.stopObserving();
    });

    test('the settled container is not re-resolved once it is correct', async () => {
        const instance = build();
        await settle();
        const settledContainer = container();
        const requestsBefore = fetchCalls.length;

        // A mutation somewhere else on the page (the intent message rendering,
        // a spinner) must not trigger another resolution.
        const noise = document.createElement('div');
        document.body.appendChild(noise);
        await settle();

        expect(container()).toBe(settledContainer);
        expect(fetchCalls.length).toBe(requestsBefore);
        instance.stopObserving();
    });
});

describe('the availability request is made once, not once per mutation', () => {
    test('a burst of mutations while the answer is outstanding issues ONE request', async () => {
        // Deliberately never resolved: this is the window in which nothing is
        // cached and `renderedForCountry` is still null, which is exactly when
        // the storm used to happen.
        global.window.fetch = (url) => {
            fetchCalls.push(url);
            return new Promise(() => {});
        };
        global.fetch = global.window.fetch;

        const instance = build();
        for (let i = 0; i < 12; i += 1) {
            document.body.appendChild(document.createElement('div'));
            jest.advanceTimersByTime(150);
            await flushPromises();
        }

        expect(fetchCalls).toHaveLength(1);
        instance.stopObserving();
    });

    test('a failed request is not cached, so a later change can ask again', async () => {
        answer.reject = true;
        const instance = build();
        await settle();
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        const afterFailure = fetchCalls.length;

        // Caching a transport blip as "not available" would make one dropped
        // request answer "not available" for the rest of the page's life.
        answer.reject = false;
        replaceContainer();
        await settle();

        expect(fetchCalls.length).toBeGreaterThan(afterFailure);
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.stopObserving();
    });

    test('stopObserving() also cancels a pending debounced refresh', async () => {
        const instance = build();
        await settle();
        const before = fetchCalls.length;

        document.body.appendChild(document.createElement('div'));
        instance.stopObserving();
        jest.advanceTimersByTime(500);
        await flushPromises();

        expect(fetchCalls.length).toBe(before);
    });
});
