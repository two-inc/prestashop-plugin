/**
 * TWO-26775: when the browser blocks the sole-trader signup popup, a panel is
 * drawn over the open popover with one centred button to try again from a click
 * of the buyer's own, and focus moves to it. Sole trader stays the selected mode
 * while it is up. Clicking it repeats the blocked launch straight away; a
 * relaunch that is blocked again keeps it (and the buyer's focus on it), one
 * that opens removes it, and so do leaving sole-trader mode and the popover
 * closing. A re-render of the address form keeps it; a country change does not.
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

const FALLBACK_TEXT = 'Sign up or log in as a sole trader';
const BLOCKED_LOG = 'Two: sole-trader signup popup was blocked and no on-page error UI is available here.';

const TOKENS = {
    success: true,
    autofill_token: 'af-token',
    delegation_token: 'del-token',
    signup_url: 'https://signup.example.test/',
    country: 'GB'
};

const NAMED_BUYER = {
    company_name: 'Sole Trader Test Co',
    organization_number: 'TWO:ST123456789012',
    email: 'buyer@example.test',
    billing_address: null,
    shipping_address: null
};

let $;
let TwoCompanySearch;
let ajax;
let soleTrader;
let search;
let reopenMemory;
let manualEntryMemory;
// What window.open() answers next: null is a blocked popup.
let nextWindow;
// Buyer lookups held open by a case, resolved by hand; null answers at once.
let heldLookups;

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
            if (heldLookups) {
                return new Promise((resolve) => heldLookups.push(resolve));
            }
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
    return $('.two-sole-trader-blocked__button');
}

/** The screen-reader announcement of a block. */
function fallbackStatus() {
    return $('.two-sole-trader-fallback__status');
}

/** A launch's refusal as TwoSoleTrader reports it. */
function refuse(launcher) {
    document.dispatchEvent(new window.CustomEvent('two:sole-trader-popup-blocked', {
        detail: { launcher: launcher, replacement: false }
    }));
}

/** A window the way the blocker lets one through. */
function realWindow() {
    return { closed: false, focus: jest.fn(), close() { this.closed = true; } };
}

/** The capture as the checkout manager builds it, on the memories every rebuild shares. */
function mount() {
    search = new TwoCompanySearch({
        checkoutHost: 'https://api.example.test',
        reopenMemory: reopenMemory,
        manualEntryMemory: manualEntryMemory
    });
    return search;
}

/** PrestaShop re-rendering the address form: the capture is torn down and built again. */
function rebuild() {
    search.destroy();
    mount();
}

/** Adopt an identity the way TwoSoleTrader hands one over. */
function adopt() {
    global.window.TwoCheckoutManager_Instance = {
        companySearch: search,
        setConfirmedCompanySelection() {}
    };
    search.adoptSoleTraderBuyer(NAMED_BUYER);
}

/** The buyer picks Sole trader and the blocker refuses the popup. */
async function blockedLaunch() {
    openPanel();
    panelParts().soleTrader.trigger('click');
    await settle();
    jest.advanceTimersByTime(TwoCompanySearch.FALLBACK_ANNOUNCE_DELAY_MS);
}

beforeEach(() => {
    jest.useFakeTimers();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    $ = loaded.$;
    TwoCompanySearch = loaded.TwoCompanySearch;
    const TwoSoleTrader = loadSoleTrader();
    buildAddressForm();
    installStylesheet('views/css/two.css');
    ajax = stubAjax($);
    heldLookups = null;
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
    reopenMemory = {};
    manualEntryMemory = {};
    mount();
});

afterEach(() => {
    search.destroy();
    soleTrader.destroy();
    releaseWidgets($);
    ajax.restore();
    jest.useRealTimers();
    delete global.window.TwoSoleTrader_Instance;
    delete global.window.TwoCheckoutManager_Instance;
    delete global.window.open;
    delete global.fetch;
    delete global.window.fetch;
    global.window.localStorage.clear();
});

describe.each([
    // [what the buyer does next, act, link shown after, mode after (null: not asserted), window.open calls, extra check, description]
    ['nothing', async () => {
    }, true, 'sole_trader', 1, () => {
        expect(document.activeElement).toBe(fallbackLink().get(0));
    }, 'the panel stays, with focus on its button, and Sole trader stays selected'],
    ['presses Escape', async () => {
        panelParts().panel.trigger($.Event('keydown', { key: 'Escape', keyCode: 27 }));
    }, false, null, 1, () => {
        expect(shown(panelParts().panel)).toBe(false);
        expect(fallbackLink().length).toBe(0);
        openPanel();
        expect(fallbackLink().length).toBe(0);
        expect(search._chipMode).toBe('registered');
    }, 'the popover closing takes the panel with it, for good'],
    ['clicks the Registered chip', async () => {
        openPanel();
        panelParts().registered.trigger('click');
    }, false, 'registered', 1, null, 'leaving sole-trader mode takes the link down'],
    ['clicks Enter manually', async () => {
        openPanel();
        panelParts().notListed.trigger('click');
    }, false, 'manual', 1, null, 'leaving sole-trader mode takes the link down'],
    ['clicks the link and the popup opens', async () => {
        nextWindow = realWindow();
        fallbackLink().trigger('click');
    }, false, 'sole_trader', 2, null, 'the relaunch opened, so the panel is spent'],
    ['clicks the link and the popup is blocked again', async () => {
        fallbackLink().get(0).focus();
        fallbackLink().trigger('click');
    }, true, 'sole_trader', 2, () => {
        expect(document.activeElement).toBe(fallbackLink().get(0));
    }, 'a still-blocked relaunch keeps the retry, and the focus on it'],
    ['clicks the Sole trader chip again and the popup is blocked again', async () => {
        openPanel();
        panelParts().soleTrader.trigger('click');
    }, true, 'sole_trader', 2, null, 'a still-blocked relaunch keeps the retry'],
    ['changes the billing country', async () => {
        $("select[name='id_country'] option").get(0).setAttribute('data-iso-code', 'NO');
        $("select[name='id_country']").get(0).dispatchEvent(new window.Event('change'));
    }, false, null, 1, null, 'a retry minted for the old country goes'],
    ['loses the address scope the search needs', async () => {
        jest.spyOn(console, 'warn').mockImplementation(() => {});
        $('form[data-id-address]').append('<div id="delivery-address"></div>');
        search.setupAutocomplete();
    }, false, null, 1, () => {
        expect(console.warn).toHaveBeenCalledWith(expect.stringContaining('company search withheld'));
    }, 'a withheld search offers no sole-trader route'],
    ['has an identity adopted', async () => {
        adopt();
    }, false, null, 1, null, 'an adopted identity spends the retry'],
    ['sees the address form re-render', async () => {
        rebuild();
        openPanel();
    }, true, 'sole_trader', 1, null, 'the rebuilt capture keeps the retry and the mode'],
    ['has the retry spent, then sees another capture\'s launch refused', async () => {
        nextWindow = realWindow();
        fallbackLink().trigger('click');
        await settle();
        refuse('another-capture');
    }, false, 'sole_trader', 2, null, 'only its own launches leave a retry'],
    ['clicks the Registered chip, then sees its own late launch refused', async () => {
        openPanel();
        panelParts().registered.trigger('click');
        refuse(search._instanceNs);
    }, false, 'registered', 1, null, 'a refusal does not bring back a mode the buyer left'],
    ['sees a re-render, clicks Registered, then the old capture\'s launch is refused', async () => {
        const old = search._instanceNs;
        rebuild();
        openPanel();
        panelParts().registered.trigger('click');
        refuse(old);
    }, false, 'registered', 1, null, 'a torn-down capture no longer answers'],
    ['changes country, then sees the address form re-render', async () => {
        $("select[name='id_country'] option").get(0).setAttribute('data-iso-code', 'NO');
        rebuild();
    }, false, null, 1, null, 'the rebuilt capture drops a retry for another country']
])('after a blocked popup, the buyer %s', (_label, act, linkAfter, modeAfter, opens, check, description) => {
    test(description, async () => {
        await blockedLaunch();
        expect(global.window.open).toHaveBeenCalledTimes(1);
        expect(console.error).toHaveBeenCalledWith(BLOCKED_LOG);
        expect(fallbackLink().length).toBe(1);
        expect(shown(fallbackLink())).toBe(true);
        expect(fallbackLink().text()).toBe(FALLBACK_TEXT);
        expect(fallbackLink().attr('type')).toBe('button');
        // Over the open popover, named, with focus on its button.
        const overlay = fallbackLink().closest('.two-sole-trader-blocked');
        expect(shown(panelParts().panel)).toBe(true);
        expect(panelParts().panel.get(0).contains(overlay.get(0))).toBe(true);
        expect(overlay.attr('aria-label')).toBe(FALLBACK_TEXT);
        expect(document.activeElement).toBe(fallbackLink().get(0));
        // The old link under the field is gone for good.
        expect($('.two-sole-trader-fallback').length).toBe(0);
        expect(fallbackStatus().attr('role')).toBe('status');
        expect(fallbackStatus().text()).toBe(FALLBACK_TEXT);

        await act();
        await settle();

        expect(shown(fallbackLink())).toBe(linkAfter);
        if (modeAfter !== null) {
            expect(search._chipMode).toBe(modeAfter);
        }
        expect(global.window.open).toHaveBeenCalledTimes(opens);
        // The relaunch is the plain signup, not a "pick a different one".
        global.window.open.mock.calls.forEach((call) => {
            expect(String(call[0])).not.toContain('autoselect');
        });
        if (check) {
            check();
        }
    });
});

test('a launch refused after its flight was let go still leaves the retry', async () => {
    // No answer held, so the click waits on a lookup of its own.
    soleTrader.clearHeldBuyerResult();
    heldLookups = [];
    openPanel();
    panelParts().soleTrader.trigger('click');
    await settle();
    expect(heldLookups.length).toBe(1);

    // Escape closes the panel, which lets the flight go.
    panelParts().panel.trigger($.Event('keydown', { key: 'Escape', keyCode: 27 }));
    expect(search._soleTraderLoading).toBe(false);

    // The lookup lands, the popup it opens is refused.
    heldLookups.splice(0).forEach((resolve) => resolve({ ok: false, status: 404 }));
    await settle();

    expect(global.window.open).toHaveBeenCalledTimes(1);
    expect(console.error).toHaveBeenCalledWith(BLOCKED_LOG);
    // The popover comes back under it.
    expect(shown(panelParts().panel)).toBe(true);
    expect(shown(fallbackLink())).toBe(true);
});

test('a refused "select a different sole trader" retries as one', async () => {
    await blockedLaunch();
    adopt();
    await settle();
    expect(shown(fallbackLink())).toBe(false);
    global.window.open.mockClear();
    console.error.mockClear();

    $('.two-company-select-different-sole-trader').trigger('click');
    await settle();
    expect(console.error).toHaveBeenCalledWith(BLOCKED_LOG);
    expect(shown(fallbackLink())).toBe(true);

    nextWindow = realWindow();
    fallbackLink().trigger('click');
    await settle();

    expect(global.window.open).toHaveBeenCalledTimes(2);
    expect(String(global.window.open.mock.calls[1][0])).toContain('autoselect=false');
    expect(shown(fallbackLink())).toBe(false);
});

test('every block is announced, a repeat one included, without moving the button', async () => {
    // In place, and silent, before anything is blocked.
    expect(fallbackStatus().length).toBe(1);
    expect(fallbackStatus().text()).toBe('');

    await blockedLaunch();
    expect(fallbackStatus().text()).toBe(FALLBACK_TEXT);
    const button = fallbackLink().get(0);

    fallbackLink().trigger('click');
    // Emptied first, so the same words are a fresh message.
    expect(fallbackStatus().text()).toBe('');
    jest.advanceTimersByTime(TwoCompanySearch.FALLBACK_ANNOUNCE_DELAY_MS);
    expect(fallbackStatus().text()).toBe(FALLBACK_TEXT);
    expect(fallbackLink().get(0)).toBe(button);

    panelParts().registered.trigger('click');
    expect(fallbackStatus().text()).toBe('');
});

test('a block of the payment-step prompt leaves no retry under the company field', async () => {
    // The payment step's own prompt, as its template renders it.
    document.body.insertAdjacentHTML('beforeend', [
        '<div class="two-sole-trader">',
        '  <a href="#" class="two-sole-trader__prompt" style="display: none;">prompt</a>',
        '  <span class="two-sole-trader__status" style="display: none;"></span>',
        '  <span class="two-sole-trader__error" style="display: none;">error</span>',
        '</div>'
    ].join(''));
    // The chip offers the prompt rather than a popup on the payment step.
    openPanel();
    panelParts().soleTrader.trigger('click');
    await settle();
    expect(global.window.open).not.toHaveBeenCalled();
    const prompt = document.querySelector('.two-sole-trader__prompt');
    expect(prompt.style.display).toBe('inline');

    prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await settle();

    expect(global.window.open).toHaveBeenCalledTimes(1);
    expect(document.querySelector('.two-sole-trader__error').style.display).toBe('inline');
    expect(fallbackLink().length).toBe(0);
});
