/**
 * TWO-25326 bug 9, chip-level half. Doug live-tested the shipped render-loop fix
 * and the "Registered business" / "Sole trader" chips still render, disappear
 * and reappear.
 *
 * WHY THE EXISTING TEST DID NOT CATCH IT
 * checkout-manager-render-loop.test.js covers TwoCheckoutManager's tile-level
 * mount/unmount guard - that handleDynamicContentChange() must not re-attach
 * document-level payment listeners on every debounced MutationObserver firing.
 * That fix was real, and it is not the chips: the chips are rendered by a
 * DIFFERENT module (TwoSoleTrader) with a render/observe cycle entirely of its
 * own, which that test never loads.
 *
 * TWO ROOT CAUSES, both in TwoSoleTrader:
 *
 *  1. "Settled" was recorded as a COUNTRY (`renderedForCountry`), not as a
 *     rendered container. PrestaShop replaces the payment fragment - and with it
 *     the whole `.two-sole-trader` container - repeatedly while the step
 *     settles. The replacement arrives with no chips in it and none of the
 *     inline display state render()/hide() set, and the observer's callback then
 *     read the country as unchanged and returned early. The chips were gone from
 *     a container the module believed it had already rendered into.
 *
 *  2. There was no in-flight guard on the availability request. The observer
 *     watches the whole body subtree while nothing is cached yet, so every
 *     mutation started ANOTHER `fetch`. Beyond the request storm, that made the
 *     toggle's visibility a race between those responses: the endpoint is
 *     fail-soft to "not available", so one failure among a dozen duplicates
 *     called hide() while its siblings called render().
 */

'use strict';

const { loadSoleTrader, buildPaymentTile, flushPromises } = require('./ps-harness');

let TwoSoleTrader;
let fetchCalls;
let answer;

/** The chips currently built into the live container, as text. */
function chipTexts() {
    return Array.from(document.querySelectorAll('.two-sole-trader__mode')).map((chip) => chip.textContent);
}

function container() {
    return document.querySelector('.two-sole-trader');
}

/**
 * Replace the `.two-sole-trader` container with a fresh copy of the template's
 * markup, exactly as a payment-fragment re-render does: same selector, new node,
 * no chips, no inline state.
 *
 * @returns {HTMLElement} the new container
 */
function replaceContainer() {
    const old = container();
    const fresh = document.createElement('div');
    fresh.className = 'two-sole-trader';
    fresh.innerHTML = '<div class="two-sole-trader__toggle"></div>'
        + '<a href="#" class="two-sole-trader__prompt" style="display: none;"></a>'
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
        shopCountry: 'NO',
        i18n: { registered_business: 'Registered business', sole_trader: 'Sole trader' }
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
});

describe('the chips survive a payment-fragment replacement', () => {
    test('a replaced container gets the chips rebuilt', async () => {
        const instance = build();
        await settle();
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);
        expect(container().style.display).toBe('block');

        // PrestaShop re-renders the payment step. Same country, brand new node.
        replaceContainer();
        expect(chipTexts()).toEqual([]);

        await settle();

        // Before the fix this stayed empty: the country was unchanged, so the
        // settled-check returned early and nothing ever rendered into the new
        // node - the chips simply stopped existing until some unrelated later
        // trigger happened to rebuild them.
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);
        expect(container().style.display).toBe('block');
        instance.stopObserving();
    });

    test('a replaced container is re-hidden when the country is not eligible', async () => {
        answer.available = false;
        const instance = build();
        await settle();
        expect(container().style.display).toBe('none');

        // The template ships `display: none`, so build the replacement VISIBLE -
        // otherwise the assertion below passes on the markup's own default and
        // proves nothing about the module.
        replaceContainer().style.display = 'block';
        await settle();

        expect(container().style.display).toBe('none');
        instance.stopObserving();
    });

    test('the settled container is not re-rendered once it is correct', async () => {
        const instance = build();
        await settle();
        const built = document.querySelector('.two-sole-trader__toggle');
        const chipsBefore = chipTexts();

        // A mutation somewhere else on the page (the intent message rendering,
        // a spinner) must not rebuild anything.
        const noise = document.createElement('div');
        document.body.appendChild(noise);
        await settle();

        expect(document.querySelector('.two-sole-trader__toggle')).toBe(built);
        expect(chipTexts()).toEqual(chipsBefore);
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
        expect(container().style.display).toBe('none');
        const afterFailure = fetchCalls.length;

        // Caching a transport blip as "not available" would make one dropped
        // request hide the toggle for the rest of the page's life.
        answer.reject = false;
        replaceContainer();
        await settle();

        expect(fetchCalls.length).toBeGreaterThan(afterFailure);
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);
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
