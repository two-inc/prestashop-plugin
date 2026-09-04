/**
 * TWO-25326 bug 9, availability-cache half. The cache
 * isAvailableForCurrentCountry() reads must settle across payment-fragment
 * replacements and request storms. Two constraints inside TwoSoleTrader:
 *
 *  1. "Settled" must be recorded as an ADOPTED CONTAINER, not as a country.
 *     PrestaShop replaces the whole `.two-sole-trader` container repeatedly
 *     while the step settles; keyed on country, the observer reads it as
 *     unchanged and answers for a container that no longer exists.
 *
 *  2. The availability request needs an in-flight guard. The observer watches
 *     the whole body subtree while nothing is cached, so every mutation starts
 *     another `fetch` - and since the endpoint is fail-soft to "not
 *     available", one failure among the duplicates can overwrite a good
 *     answer another had just applied.
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
 * Replace the container as a payment-fragment re-render does: same selector,
 * new node, no answer adopted, no inline state.
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
    // The availability cache lives in localStorage, not the instance, so it is
    // cross-test state: a later fetch assertion would see an earlier write.
    global.window.localStorage.clear();
});

describe('the availability cache survives a payment-fragment replacement', () => {
    test('a replaced container gets its availability re-adopted', async () => {
        const instance = build();
        await settle();
        expect(instance.isAvailableForCurrentCountry()).toBe(true);

        // Same country, brand new node.
        replaceContainer();

        await settle();

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
        // Never resolved: the window in which nothing is cached yet.
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

        // One availability request only - the unconditional mint at
        // construction is a separate call, outside what this debounce covers.
        const availabilityCalls = fetchCalls.filter((url) => String(url).includes('soleTraderAvailability'));
        expect(availabilityCalls).toHaveLength(1);
        instance.stopObserving();
    });

    test('a failed request is not cached, so a later change can ask again', async () => {
        answer.reject = true;
        const instance = build();
        await settle();
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        const afterFailure = fetchCalls.length;

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
