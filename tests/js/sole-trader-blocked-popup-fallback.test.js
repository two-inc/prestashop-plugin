/**
 * TWO-26775: when the browser blocks the sole-trader signup popup, the buyer
 * gets a link under the company field to try again from a click of their own.
 * Sole trader stays the selected mode while it is up. Clicking it relaunches
 * the signup straight away; a relaunch that is blocked again keeps it, one
 * that opens removes it, and any chip that leaves sole-trader mode removes it.
 * Real modules, no stubs beyond the network and window.open().
 */

'use strict';

const {
    loadCompanySearch,
    loadSoleTrader,
    buildAddressForm,
    installStylesheet,
    stubAjax,
    releaseWidgets,
    panelParts,
    openPanel,
    shown,
    flushPromises
} = require('./ps-harness');

const FALLBACK_TEXT = 'Click here to login or sign up as a sole trader.';

const TOKENS = {
    success: true,
    autofill_token: 'af-token',
    delegation_token: 'del-token',
    signup_url: 'https://signup.example.test/',
    country: 'GB'
};

let $;
let ajax;
let soleTrader;
let search;
// What window.open() answers next: null is a blocked popup.
let nextWindow;

/** Tokens minted, no buyer known: the address page opens the popup straight away. */
function stubFetch() {
    global.window.fetch = (url) => {
        const target = String(url);
        if (target.includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        if (target.includes('soleTraderTokens')) {
            return Promise.resolve({ json: () => Promise.resolve(TOKENS) });
        }
        if (target.includes('/autofill/v1/buyer/current')) {
            return Promise.resolve({ ok: false, status: 404 });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;
}

async function settle() {
    await flushPromises();
    await flushPromises();
    await flushPromises();
    jest.advanceTimersByTime(1);
}

function fallbackLink() {
    return $('.two-sole-trader-fallback__link');
}

/** A window the way the blocker lets one through. */
function realWindow() {
    return { closed: false, focus: jest.fn(), close() { this.closed = true; } };
}

beforeEach(async () => {
    jest.useFakeTimers();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    $ = loaded.$;
    const TwoSoleTrader = loadSoleTrader();
    buildAddressForm();
    installStylesheet('views/css/two.css');
    ajax = stubAjax($);
    stubFetch();
    nextWindow = null;
    global.window.open = jest.fn(() => nextWindow);
    jest.spyOn(console, 'error').mockImplementation(() => {});
    soleTrader = new TwoSoleTrader({
        checkoutHost: 'https://api.example.test',
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        billingCountry: 'GB'
    });
    global.window.TwoSoleTrader_Instance = soleTrader;
    search = new loaded.TwoCompanySearch({
        checkoutHost: 'https://api.example.test',
        reopenMemory: {},
        manualEntryMemory: {}
    });

    // The buyer picks Sole trader and the blocker refuses the popup.
    openPanel();
    panelParts().soleTrader.trigger('click');
    await settle();
});

afterEach(() => {
    search.destroy();
    soleTrader.destroy();
    releaseWidgets($);
    ajax.restore();
    jest.useRealTimers();
    delete global.window.TwoSoleTrader_Instance;
    delete global.window.open;
    delete global.fetch;
    delete global.window.fetch;
    global.window.localStorage.clear();
});

describe.each([
    // [what the buyer does next, act, link shown after, mode after, window.open calls, description]
    ['nothing, then reopens the panel', async () => {
        openPanel();
    }, true, 'sole_trader', 1, 'the link stays and Sole trader stays selected'],
    ['clicks the Registered chip', async () => {
        openPanel();
        panelParts().registered.trigger('click');
    }, false, 'registered', 1, 'leaving sole-trader mode takes the link down'],
    ['clicks Enter manually', async () => {
        openPanel();
        panelParts().notListed.trigger('click');
    }, false, 'manual', 1, 'leaving sole-trader mode takes the link down'],
    ['clicks the link and the popup opens', async () => {
        nextWindow = realWindow();
        fallbackLink().trigger('click');
    }, false, 'sole_trader', 2, 'the relaunch opened, so the retry is spent'],
    ['clicks the link and the popup is blocked again', async () => {
        fallbackLink().trigger('click');
    }, true, 'sole_trader', 2, 'a still-blocked relaunch keeps the retry'],
    ['clicks the Sole trader chip again and the popup is blocked again', async () => {
        openPanel();
        panelParts().soleTrader.trigger('click');
    }, true, 'sole_trader', 2, 'a still-blocked relaunch keeps the retry']
])('after a blocked popup, the buyer %s', (_label, act, linkAfter, modeAfter, opens, description) => {
    test(description, async () => {
        expect(global.window.open).toHaveBeenCalledTimes(1);
        expect(fallbackLink().length).toBe(1);
        expect(shown(fallbackLink())).toBe(true);
        expect(fallbackLink().text()).toBe(FALLBACK_TEXT);
        expect(fallbackLink().attr('type')).toBe('button');

        await act();
        await settle();

        expect(shown(fallbackLink())).toBe(linkAfter);
        expect(search._chipMode).toBe(modeAfter);
        expect(global.window.open).toHaveBeenCalledTimes(opens);
        // The relaunch is the plain signup, not a "pick a different one".
        global.window.open.mock.calls.forEach((call) => {
            expect(String(call[0])).not.toContain('autoselect');
        });
    });
});
