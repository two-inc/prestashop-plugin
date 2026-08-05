/**
 * TWO-25326 bug 9, round 3. Doug live-tested the round-2 fix and the chips STILL
 * "render, then disappear and reappear again".
 *
 * WHY ROUND 2 COULD NOT HAVE FIXED IT. Round 2 keyed the settled-check on the
 * container node and put an in-flight guard on the availability request. Both
 * were real defects, and neither is this one: they are about not redoing work
 * AFTER an answer arrives, and the flicker lives entirely in the window BEFORE
 * it arrives. Measured on the staging shop with a rAF-rate sampler: the toggle
 * is `display:none` with zero chips at first paint and only becomes
 * `display:block` with two chips ~280ms later, on EVERY load - because the chips
 * were built only by this module, only after a round trip. Selecting a payment
 * option reloads the whole checkout page (the surcharge cart-line sync asks
 * PrestaShop core to refresh the cart, and on the payment step core's refresh is
 * a full reload), so the buyer sees chips, then a document without chips, then
 * chips again. Nothing that runs after the answer can close that window; the
 * answer has to already be in the markup.
 *
 * So paymentinfo.tpl now renders the toggle from the server-side registry answer
 * and this module ADOPTS it. These tests are about that handover: the adopted
 * state must be complete (visible, chipped, and the chips must WORK), must cost
 * no request, and must not be trusted further than it goes - a different country
 * still re-resolves, and markup with no answer in it still falls back to the
 * fetch.
 */

'use strict';

const {
    loadSoleTrader,
    buildPaymentTile,
    buildPaymentTileWithSoleTraderAnswer,
    flushPromises
} = require('./ps-harness');

let TwoSoleTrader;
let fetchCalls;
let answer;

function container() {
    return document.querySelector('.two-sole-trader');
}

function chipTexts() {
    return Array.from(document.querySelectorAll('.two-sole-trader__mode')).map((chip) => chip.textContent.trim());
}

function selectedChipModes() {
    return Array.from(document.querySelectorAll('.two-sole-trader__mode--selected')).map((chip) => chip.dataset.mode);
}

function buildCountry(iso) {
    const holder = document.createElement('div');
    holder.innerHTML = "<select name='id_country'>"
        + '<option value="17" data-iso-code="' + iso + '" selected>Country</option>'
        + '</select>';
    document.body.appendChild(holder);
    return holder.querySelector('select');
}

/** Let the debounced observer callback and any promise chain run. */
async function settle() {
    jest.advanceTimersByTime(150);
    await flushPromises();
    jest.advanceTimersByTime(150);
    await flushPromises();
}

function build() {
    return new TwoSoleTrader({
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        shopCountry: 'GB',
        i18n: { registered_business: 'Registered business', sole_trader: 'Sole trader' }
    });
}

beforeEach(() => {
    jest.useFakeTimers();
    delete global.window.TwoSoleTrader;
    fetchCalls = [];
    answer = { available: true };
    global.window.fetch = (url) => {
        fetchCalls.push(url);
        return Promise.resolve({ json: () => Promise.resolve({ success: true, available: answer.available }) });
    };
    global.fetch = global.window.fetch;
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

describe('a server-rendered toggle is adopted, not rebuilt', () => {
    test('the chips are present and visible with no request at all', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();

        // Before construction, i.e. what the buyer sees at FIRST PAINT. This is
        // the assertion the round-2 fix could not make: previously the template
        // shipped an empty, hidden container and there was nothing here at all.
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);
        expect(container().getAttribute('style')).toContain('display: block');

        const instance = build();
        await settle();

        // Still there, still visible - and not a single availability request.
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);
        expect(fetchCalls).toEqual([]);
        instance.stopObserving();
    });

    test('the adopted chips actually switch mode when clicked', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        // The whole risk of moving the markup server-side: chips that look
        // right and do nothing, because the listeners used to be attached in
        // the same statement that created the element.
        expect(selectedChipModes()).toEqual(['business']);
        const soleTraderChip = document.querySelector('.two-sole-trader__mode[data-mode="sole_trader"]');
        soleTraderChip.dispatchEvent(new window.Event('click', { bubbles: true }));
        await flushPromises();

        expect(instance.mode).toBe('sole_trader');
        expect(selectedChipModes()).toEqual(['sole_trader']);
        instance.stopObserving();
    });

    test('keyboard activation works on the adopted chips too', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        const soleTraderChip = document.querySelector('.two-sole-trader__mode[data-mode="sole_trader"]');
        const press = new window.Event('keypress', { bubbles: true });
        press.which = 13;
        soleTraderChip.dispatchEvent(press);
        await flushPromises();

        expect(instance.mode).toBe('sole_trader');
        instance.stopObserving();
    });

    test('a server answer of "not available" is adopted as hidden, with no request', async () => {
        buildPaymentTileWithSoleTraderAnswer(false, 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();

        expect(chipTexts()).toEqual([]);
        expect(container().getAttribute('style')).toContain('display: none');

        const instance = build();
        await settle();

        expect(chipTexts()).toEqual([]);
        expect(fetchCalls).toEqual([]);
        instance.stopObserving();
    });

    test('the chips are bound exactly once, however many renders happen', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        // Force render() to run over the already-bound, server-rendered chips.
        // A second listener per chip would make one click toggle mode twice -
        // invisible in the DOM, but it would fire the token mint twice.
        instance.render();
        instance.render();
        const calls = [];
        instance.setMode = (mode) => { calls.push(mode); };

        document.querySelector('.two-sole-trader__mode[data-mode="sole_trader"]')
            .dispatchEvent(new window.Event('click', { bubbles: true }));

        expect(calls).toEqual(['sole_trader']);
        instance.stopObserving();
    });
});

describe('the adopted answer is trusted exactly as far as it goes', () => {
    test('a different billing country still resolves over the network', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'GB');
        // The buyer's billing country is NOT the one the server rendered for -
        // e.g. the address was changed in a tab the payment step outlived.
        buildCountry('NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NO');
        instance.stopObserving();
    });

    test('a country change after adoption is resolved for the new country', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();
        expect(fetchCalls).toEqual([]);

        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=SE');
        instance.stopObserving();
    });

    test('markup with no server answer falls back to the request', async () => {
        // buildPaymentTile() leaves the sole-trader blocks unevaluated, which is
        // what an older cached template or a theme-rebuilt tile looks like: the
        // data- attributes are empty rather than "1"/"0".
        buildPaymentTile();
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);
        instance.stopObserving();
    });

    test('a stopped instance no longer answers a country change', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        instance.stopObserving();
        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await settle();

        // The country-change listener used to be an anonymous closure with no
        // way to remove it, so an instance that had finished its flow kept
        // re-resolving availability and re-rendering the toggle it had
        // deliberately stopped maintaining.
        expect(fetchCalls).toEqual([]);
    });

    test('a malformed country attribute is not adopted', async () => {
        buildPaymentTileWithSoleTraderAnswer(true, 'not-an-iso');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        // The answer cannot be filed against a country, so it is no answer.
        expect(fetchCalls).toHaveLength(1);
        instance.stopObserving();
    });
});
