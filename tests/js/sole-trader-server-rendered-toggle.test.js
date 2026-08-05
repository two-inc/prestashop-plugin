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

function build(overrides) {
    return new TwoSoleTrader(Object.assign({
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        // Deliberately DIFFERENT from the fixtures' country, so any test that
        // resolves the country from this rather than from the cart's billing
        // country or the live select shows up as a wrong-country request.
        shopCountry: 'ZZ',
        billingCountry: 'GB',
        i18n: { registered_business: 'Registered business', sole_trader: 'Sole trader' }
    }, overrides || {}));
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
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
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
        instance.destroy();
    });

    test('the adopted chips actually switch mode when clicked', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
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
        instance.destroy();
    });

    test('keyboard activation works on the adopted chips too', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
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
        instance.destroy();
    });

    test('a server answer of "not available" is adopted as hidden, with no request', async () => {
        buildPaymentTileWithSoleTraderAnswer('0', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();

        expect(chipTexts()).toEqual([]);
        expect(container().getAttribute('style')).toContain('display: none');

        const instance = build();
        await settle();

        expect(chipTexts()).toEqual([]);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });

    test('the chips are bound exactly once, however many renders happen', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
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
        instance.destroy();
    });
});

describe('the adopted answer is trusted exactly as far as it goes', () => {
    test('a different billing country still resolves over the network', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        // The buyer's billing country is NOT the one the server rendered for -
        // e.g. the address was changed in a tab the payment step outlived.
        buildCountry('NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NO');
        instance.destroy();
    });

    test('a country change after adoption is resolved for the new country', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
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
        instance.destroy();
    });

    test('an UNRESOLVED answer falls back to the request rather than caching a "no"', async () => {
        // The registry did not answer. The container is drawn hidden and
        // chipless - fail-soft is unchanged - but the browser must NOT record
        // that as "business-only country", or one blip becomes permanent for the
        // page: adoption never re-asks.
        buildPaymentTileWithSoleTraderAnswer('', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        expect(instance.renderedForCountry).toBeNull();
        expect(instance.availabilityByCountry).toEqual({});

        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);
        instance.destroy();
    });

    test('an answer that is neither "1" nor "0" is not adopted', async () => {
        // A theme or a future template emitting e.g. "true". Adopting it would
        // read as `false` - toggle hidden, no request, sole trader silently
        // unavailable. The country here is VALID on purpose: with a bad country
        // too, the country guard rejects first and this guard is never the
        // deciding branch.
        buildPaymentTileWithSoleTraderAnswer('yes', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        expect(instance.renderedForCountry).toBeNull();
        expect(instance.availabilityByCountry).toEqual({});

        await settle();

        expect(fetchCalls).toHaveLength(1);
        instance.destroy();
    });

    test('markup with no server answer at all falls back to the request, and those chips work', async () => {
        // buildPaymentTile() leaves the sole-trader blocks unevaluated, which is
        // what an older cached template or a theme-rebuilt tile looks like.
        buildPaymentTile();
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(chipTexts()).toEqual(['Registered business', 'Sole trader']);

        // And they must WORK. This PR moved the chip listeners out of the
        // chip-building loop into bindChips(), so the client-rendered path has
        // its own way of shipping chips that look right and do nothing - and
        // every other click test in this file exercises the adopt path.
        document.querySelector('.two-sole-trader__mode[data-mode="sole_trader"]')
            .dispatchEvent(new window.Event('click', { bubbles: true }));
        await flushPromises();

        expect(instance.mode).toBe('sole_trader');
        instance.destroy();
    });

    test('a malformed country attribute is not adopted', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'not-an-iso');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        // Asserted on the adoption state directly, not on "a request happened":
        // the answer would be filed under the bogus key, which the DOM country
        // never matches, so a request happens under BOTH branches and that
        // assertion alone proves nothing.
        expect(instance.renderedForCountry).toBeNull();
        expect(instance.availabilityByCountry).toEqual({});

        await settle();
        expect(fetchCalls).toHaveLength(1);
        instance.destroy();
    });
});

describe('which country the answer is resolved for', () => {
    test('with no country select on the page, the CART billing country is used', async () => {
        // The real payment step: PrestaShop renders no address form there, so
        // there is no `select[name=id_country]` to read. Falling back to the
        // shop/visitor country resolved availability for the wrong country and
        // applied it over the server-rendered answer.
        buildPaymentTileWithSoleTraderAnswer('', 'NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'NO', shopCountry: 'ZZ' });
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NO');
        instance.destroy();
    });

    test('the shop country is only a last resort', async () => {
        buildPaymentTileWithSoleTraderAnswer('', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: '', shopCountry: 'SE' });
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=SE');
        instance.destroy();
    });

    test('a live country select still outranks both', async () => {
        buildPaymentTileWithSoleTraderAnswer('', 'GB');
        buildCountry('DK');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'NO', shopCountry: 'SE' });
        await settle();

        // A buyer mid-edit may have picked a country that is not saved yet.
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=DK');
        instance.destroy();
    });
});

describe('a replaced container is re-adopted from its own markup', () => {
    /** Replace the container with a fresh SERVER-rendered copy, as PS does. */
    function replaceWithServerRendered(answer, countryIso) {
        const old = container();
        const holder = document.createElement('div');
        holder.innerHTML = '<div class="two-sole-trader" data-two-country="' + countryIso + '"'
            + ' data-two-available="' + answer + '" style="display: '
            + (answer === '1' ? 'block' : 'none') + ';">'
            + '<div class="two-sole-trader__toggle"' + (answer === '1' ? ' data-two-built="1"' : '') + '>'
            + (answer === '1'
                ? '<span class="two-sole-trader__mode two-sole-trader__mode--selected" role="button" tabindex="0" data-mode="business">Registered business</span>'
                + '<span class="two-sole-trader__mode" role="button" tabindex="0" data-mode="sole_trader">Sole trader</span>'
                : '')
            + '</div>'
            + '<a href="#" class="two-sole-trader__prompt" style="display: none;"></a>'
            + '<span class="two-sole-trader__status" style="display: none;"></span>'
            + '<span class="two-sole-trader__error" style="display: none;"></span>'
            + '</div>';
        const fresh = holder.querySelector('.two-sole-trader');
        old.parentNode.replaceChild(fresh, old);
        return fresh;
    }

    test("the replacement's chips are bound without waiting out the refresh debounce", async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        replaceWithServerRendered('1', 'GB');
        // NO timer advance: the container-identity check is deliberately not
        // debounced, because until it runs the replacement's chips are real
        // chips with no behaviour on them.
        await flushPromises();

        document.querySelector('.two-sole-trader__mode[data-mode="sole_trader"]')
            .dispatchEvent(new window.Event('click', { bubbles: true }));
        await flushPromises();

        expect(instance.mode).toBe('sole_trader');
        instance.destroy();
    });

    test("a fresher server answer in the replacement is not overwritten by the stale cache", async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();
        expect(container().getAttribute('style')).toContain('display: block');

        // Same country, but the server now says business-only. The cached `true`
        // used to win and re-render the toggle as available.
        replaceWithServerRendered('0', 'GB');
        await settle();

        expect(container().style.display).toBe('none');
        expect(chipTexts()).toEqual([]);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });
    test('the country-change path re-adopts too, not only the observer', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        // Observer off, so the ONLY route into refreshAvailability() is the
        // country-change listener. That listener can fire after a fragment
        // replacement whose mutations the observer never saw or has not drained,
        // so it has to re-adopt as well - otherwise the stale cache re-renders
        // the toggle over the replacement's own, fresher answer.
        instance.stopObserving();
        replaceWithServerRendered('0', 'GB');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await flushPromises();

        expect(container().style.display).toBe('none');
        expect(chipTexts()).toEqual([]);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });
});

describe('lifecycle', () => {
    test('stopObserving() still lets a country change hide a now-ineligible toggle', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        // stopObserving() means "this flow is resolved", not "this instance is
        // gone". An enrolled buyer who then switches to a business-only country
        // must still have the toggle taken away.
        instance.stopObserving();
        answer.available = false;
        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await settle();

        expect(fetchCalls).toHaveLength(1);
        expect(container().style.display).toBe('none');
        instance.destroy();
    });

    test('destroy() detaches the country-change listener too', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await settle();

        instance.destroy();
        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await settle();

        // The handler used to be an anonymous closure with no reference kept, so
        // there was no way to detach it at all - a disposed instance stayed a
        // live second writer to the toggle container.
        expect(fetchCalls).toEqual([]);
    });
});
