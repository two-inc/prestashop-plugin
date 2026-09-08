/**
 * TWO-25658: focus landing on a checkout CONTROL settles the open signup popup - the Sole
 * trader chip raises it, anything else closes it (close only). Real modules, no stubs.
 */

'use strict';

const {
    loadCompanySearch,
    loadSoleTrader,
    loadScript,
    buildAddressForm,
    installStylesheet,
    stubAjax,
    releaseWidgets,
    panelParts,
    openPanel,
    shown,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const TOKENS = {
    success: true,
    autofill_token: 'af-token',
    delegation_token: 'del-token',
    signup_url: 'https://signup.example.test/',
    country: 'GB'
};

/** A sole trader with no name of their own: the synthetic number stands in, and there is nothing to write into the form. */
const NAMELESS_BUYER = {
    company_name: '',
    organization_number: 'TWO:ST000000000001',
    email: 'buyer@example.test',
    billing_address: null,
    shipping_address: null
};

const NAMED_BUYER = {
    company_name: 'Sole Trader Test Co',
    organization_number: 'TWO:ST123456789012',
    email: 'buyer@example.test',
    billing_address: null,
    shipping_address: null
};

let TwoCompanySearch;
let TwoSoleTrader;
let $;
let ajax;
let soleTrader;
let popup;
let tokenMints;
let companySaves;
let heldSaves;
let instances;
// One per capture, shared across that capture's rebuilds, as the checkout manager's is.
let reopenMemories;
let manualEntryMemories;
let heldMints;
// A buyer for the lookup to answer with; null answers 404.
let lookupBuyer;

/** `close()` flips `closed` the way a real window does, so a second close is observably a no-op. */
function fakePopup() {
    return {
        closed: false,
        closeCalls: 0,
        close() {
            this.closeCalls += 1;
            this.closed = true;
        },
        focus: jest.fn()
    };
}

/** Availability "available", tokens minted, no buyer known - the containerless address page opens the popup straight away. */
function stubFetch() {
    global.window.fetch = (url) => {
        const target = String(url);
        if (target.includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        if (target.includes('soleTraderTokens')) {
            tokenMints += 1;
            if (heldMints) {
                return new Promise((resolve) => heldMints.push(resolve));
            }
            return Promise.resolve({ json: () => Promise.resolve(TOKENS) });
        }
        if (target.includes('saveCompany')) {
            companySaves += 1;
            if (heldSaves) {
                return new Promise((resolve) => heldSaves.push(resolve));
            }
        }
        if (target.includes('/autofill/v1/buyer/current')) {
            if (lookupBuyer) {
                return Promise.resolve({ ok: true, json: () => Promise.resolve(lookupBuyer) });
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;
}

function makeInstance(config) {
    const selector = (config && config.companyFieldSelector) || "input[name='company']";
    reopenMemories[selector] = reopenMemories[selector] || {};
    manualEntryMemories[selector] = manualEntryMemories[selector] || {};
    const instance = new TwoCompanySearch(Object.assign(
        {
            checkoutHost: CHECKOUT_HOST,
            reopenMemory: reopenMemories[selector],
            manualEntryMemory: manualEntryMemories[selector]
        },
        config || {}
    ));
    instances.push(instance);
    return instance;
}

async function settle() {
    await flushPromises();
    await flushPromises();
    await flushPromises();
}

/** Open the panel and click the Sole trader chip through to an open popup. */
async function launchWithPopupOpen(instance) {
    const search = instance || makeInstance();
    search.companyField.trigger('mousedown');
    search._soleTraderButton.trigger('click');
    await settle();
    expect(global.window.open).toHaveBeenCalledTimes(1);
    expect(soleTrader.isPopupOpen()).toBe(true);
    // The deferred panel close the launcher's blur scheduled runs, and stands down.
    jest.advanceTimersByTime(1);
    expect(shown(panelParts().panel)).toBe(true);
    return search;
}

/** Every token mint held since `heldMints` was set succeeds. */
function releaseMints() {
    heldMints.splice(0).forEach((resolve) => resolve({ json: () => Promise.resolve(TOKENS) }));
}

/** The manager as the popup module sees it: the live capture, and a record of every publish. */
function stubManager(companySearch) {
    const publishes = [];
    global.window.TwoCheckoutManager_Instance = {
        companySearch: companySearch,
        setConfirmedCompanySelection(selection) {
            publishes.push(selection);
        }
    };
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    jest.spyOn(soleTrader, 'recheckOrderIntent').mockImplementation(() => {});
    return publishes;
}

/** PrestaShop's re-render: the company field is a new node, the old one gone. */
function rerenderField(id) {
    const old = document.getElementById(id);
    const fresh = old.cloneNode(false);
    old.parentNode.replaceChild(fresh, old);
    return fresh;
}

/** Second address block, for the two-capture cases; the first block's field gets an id to be selected by. */
function addSecondAddressBlock() {
    document.querySelector("input[name='company']").id = 'company-a';
    const block = document.createElement('div');
    block.id = 'invoice-address';
    block.innerHTML = [
        '<div class="js-address-form">',
        '  <form method="POST" data-id-address="9">',
        '    <input type="text" name="company" id="company-b" value="" />',
        "    <input type='text' name='address1' value='' />",
        '    <select name="id_country"><option value="17" data-iso-code="GB" selected>GB</option></select>',
        '  </form>',
        '</div>'
    ].join('\n');
    document.body.appendChild(block);
}

beforeEach(() => {
    jest.useFakeTimers();
    instances = [];
    reopenMemories = {};
    manualEntryMemories = {};
    heldMints = null;
    lookupBuyer = null;
    tokenMints = 0;
    companySaves = 0;
    heldSaves = null;
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    TwoSoleTrader = loadSoleTrader();
    buildAddressForm();
    installStylesheet('views/css/two.css');
    ajax = stubAjax($);
    stubFetch();
    popup = fakePopup();
    global.window.open = jest.fn(() => popup);
    soleTrader = new TwoSoleTrader({
        checkoutHost: CHECKOUT_HOST,
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        billingCountry: 'GB'
    });
    global.window.TwoSoleTrader_Instance = soleTrader;
});

afterEach(() => {
    instances.forEach((instance) => instance.destroy());
    soleTrader.destroy();
    releaseWidgets($);
    ajax.restore();
    jest.useRealTimers();
    delete global.window.TwoSoleTrader_Instance;
    delete global.window.TwoCheckoutManager_Instance;
    delete global.window.TwoCompanyNumber;
    delete global.window.open;
    delete global.fetch;
    delete global.window.fetch;
    global.window.localStorage.clear();
});

describe('Doug\'s three rules on focus (TWO-25658)', () => {
    const TARGETS = {
        'the Sole trader chip': () => panelParts().soleTrader.get(0),
        'the Registered chip': () => panelParts().registered.get(0),
        'the Enter manually chip': () => panelParts().notListed.get(0),
        'a non-chip control inside the panel': () => panelParts().query.get(0),
        'a control outside the panel': () => panelParts().nameField.get(0),
        'a "Select a different sole trader" button': () => {
            const button = document.createElement('button');
            button.className = 'two-company-select-different-sole-trader';
            document.body.appendChild(button);
            return button;
        }
    };

    /** The popup is gone, the panel kept and its flight live: the buyer came back into the panel. */
    function closeFromInside() {
        panelParts().query.get(0).focus();
        expect(popup.closed).toBe(true);
        popup = fakePopup();
    }

    test.each([
        ['popup open', 'the Sole trader chip', 1, false, true, true, 'rule 1: raised, nothing else changes'],
        ['popup open', 'the Registered chip', 1, true, false, true, 'rule 2: closed on focus arrival, the click is still to come'],
        ['popup open', 'the Enter manually chip', 1, true, false, true, 'rule 2: closed on focus arrival'],
        ['popup open', 'a non-chip control inside the panel', 1, true, false, true, 'rule 2: closed, panel kept'],
        ['popup open', 'a control outside the panel', 1, true, false, false, 'rules 2+3: popup and panel close'],
        ['popup open', 'a "Select a different sole trader" button', 1, true, false, false, 'rule 2: a control like any other, its click relaunches'],
        ['popup closed', 'the Sole trader chip', 2, false, false, true, 'rule 1: opened afresh'],
        ['popup closed', 'the Registered chip', 1, false, false, true, 'nothing to close'],
        ['popup closed', 'the Enter manually chip', 1, false, false, true, 'nothing to close'],
        ['popup closed', 'a non-chip control inside the panel', 1, false, false, true, 'nothing to close'],
        ['popup closed', 'a control outside the panel', 1, false, false, false, 'rule 3: the panel closes'],
        ['popup closed', 'a "Select a different sole trader" button', 1, false, false, false, 'rule 3: the panel closes']
    ])('%s, focus lands on %s: opens=%s closed=%s raised=%s panelOpen=%s - %s', async (state, target, opens, closed, raised, panelOpen) => {
        await launchWithPopupOpen();
        if (state === 'popup closed') {
            closeFromInside();
        }
        const generation = soleTrader._enrollGeneration;

        TARGETS[target]().focus();
        await settle();
        jest.advanceTimersByTime(10);

        expect(global.window.open).toHaveBeenCalledTimes(opens);
        expect(popup.closed).toBe(closed);
        expect(popup.focus.mock.calls.length > 0).toBe(raised);
        expect(shown(panelParts().panel)).toBe(panelOpen);
        // Close only, ever: the enrolment is untouched.
        expect(soleTrader.enrolling).toBe(true);
        expect(soleTrader._enrollGeneration).toBe(generation);
    });

    test('a focusin on body changes nothing', async () => {
        await launchWithPopupOpen();

        document.body.dispatchEvent(new global.window.FocusEvent('focusin', { bubbles: true }));

        expect(popup.closed).toBe(false);
        expect(popup.focus).not.toHaveBeenCalled();
    });

    test('a popup closed by focus is reopened by the next chip focus without a re-mint', async () => {
        await launchWithPopupOpen();
        const mintsBefore = tokenMints;

        panelParts().nameField.get(0).focus();
        expect(popup.closed).toBe(true);
        popup = fakePopup();
        openPanel();
        panelParts().soleTrader.get(0).focus();
        await settle();

        expect(global.window.open).toHaveBeenCalledTimes(2);
        expect(tokenMints).toBe(mintsBefore);
    });

    test('a mouse click on the Sole trader chip - focus then click - opens one popup and raises it', async () => {
        await launchWithPopupOpen();

        panelParts().soleTrader.get(0).focus();
        panelParts().soleTrader.trigger('click');
        await settle();

        expect(popup.focus.mock.calls.length).toBeGreaterThan(0);
        expect(popup.closed).toBe(false);
        expect(global.window.open).toHaveBeenCalledTimes(1);
    });

    test('a close from inside the panel leaves the flight, so the settle that follows keeps the panel and focus', async () => {
        await launchWithPopupOpen();

        panelParts().query.get(0).focus();
        expect(popup.closed).toBe(true);
        jest.advanceTimersByTime(600);

        expect(shown(panelParts().panel)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
    });

    test('a hand-closed window the poll has not noticed, then a click into the panel: the settle keeps the panel and focus', async () => {
        await launchWithPopupOpen();

        popup.closed = true;
        panelParts().query.get(0).focus();
        jest.advanceTimersByTime(600);

        expect(shown(panelParts().panel)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
    });

    test('a close from inside the panel with the write still out keeps the spinner until the write lands, then the panel', async () => {
        await launchWithPopupOpen();
        soleTrader._pendingAdoptionWrites += 1;

        panelParts().query.get(0).focus();
        expect(popup.closed).toBe(true);
        jest.advanceTimersByTime(600);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(true);
        expect(soleTrader._settleDeferred).toBe(true);

        soleTrader._pendingAdoptionWrites -= 1;
        soleTrader.flushDeferredSettle();

        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
        expect(shown(panelParts().panel)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
    });

    test('after a close from inside the panel, the Sole trader chip launches a fresh popup while the write is still out', async () => {
        await launchWithPopupOpen();
        soleTrader._pendingAdoptionWrites += 1;
        panelParts().query.get(0).focus();
        expect(popup.closed).toBe(true);
        popup = fakePopup();

        panelParts().soleTrader.trigger('click');
        await settle();

        expect(global.window.open).toHaveBeenCalledTimes(2);
        expect(soleTrader.isPopupOpen()).toBe(true);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(true);
        expect(shown(panelParts().panel)).toBe(true);
    });

    test('the buyer closing the window themselves, focusing nothing, settles into a closed panel with focus on the company field', async () => {
        await launchWithPopupOpen();

        popup.closed = true;
        jest.advanceTimersByTime(600);

        expect(shown(panelParts().panel)).toBe(false);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
        expect(document.activeElement).toBe(panelParts().nameField.get(0));
    });

    test('a return closes the popup once; later focus finds nothing to close', async () => {
        await launchWithPopupOpen();

        panelParts().nameField.get(0).focus();
        $("input[name='dni']").get(0).focus();

        expect(popup.closeCalls).toBe(1);
    });
});

describe('the other mode chips (Doug: close on focus, the click never cancels, the mode change proceeds)', () => {
    test.each([
        ['registered', 'the query row is back and focused'],
        ['notListed', 'manual entry, company field focused']
    ])('%s chip: focus closes the popup, the click switches mode without cancelling - %s', async (chip) => {
        await launchWithPopupOpen();
        const generation = soleTrader._enrollGeneration;
        const node = panelParts()[chip].get(0);

        node.focus();
        expect(popup.closeCalls).toBe(1);
        panelParts()[chip].trigger('click');
        jest.advanceTimersByTime(10);

        expect(popup.closeCalls).toBe(1);
        expect(soleTrader.enrolling).toBe(true);
        expect(soleTrader._enrollGeneration).toBe(generation);
        if (chip === 'registered') {
            expect(shown(panelParts().panel)).toBe(true);
            expect(shown(panelParts().searchRow)).toBe(true);
            expect(document.activeElement).toBe(panelParts().query.get(0));
        } else {
            expect(shown(panelParts().panel)).toBe(false);
            expect(panelParts().nameField.attr('readonly')).toBeUndefined();
            expect(document.activeElement).toBe(panelParts().nameField.get(0));
        }
    });

    test('the settle after a Registered click leaves the kept panel open and focus where the buyer put it', async () => {
        await launchWithPopupOpen();

        panelParts().registered.get(0).focus();
        panelParts().registered.trigger('click');
        jest.advanceTimersByTime(600);

        expect(shown(panelParts().panel)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
    });

    test('reopening the panel closes a live popup and leaves its enrolment resumable', async () => {
        await launchWithPopupOpen();
        const generation = soleTrader._enrollGeneration;

        openPanel();

        expect(popup.closed).toBe(true);
        expect(soleTrader.enrolling).toBe(true);
        expect(soleTrader._enrollGeneration).toBe(generation);
        expect(shown(panelParts().panel)).toBe(true);
    });
});

describe('focus the plugin moves itself', () => {
    const OWN_MOVES = {
        'Escape returning focus to the company field': () => {
            panelParts().panel.trigger($.Event('keydown', { key: 'Escape' }));
            expect(document.activeElement).toBe(panelParts().nameField.get(0));
        },
        'a re-render restore focusing the panel': (instance) => {
            instance.closeDropdown(false);
            instance.armReopen(Date.now() + 1000);
            instance.restorePanelAfterRerender();
            expect(shown(panelParts().panel)).toBe(true);
            expect(panelParts().panel.get(0).contains(document.activeElement)).toBe(true);
        },
        'validation focusing an invalid optional field': () => {
            loadScript('views/js/modules/TwoOptionalFields.js');
            const form = document.querySelector('form');
            form.insertAdjacentHTML('beforeend',
                '<input type="hidden" name="two_invoice_email" />'
                + '<input type="text" data-two-optional-field="invoice_email" data-two-optional-target="two_invoice_email" value="not-an-email" />');
            const fields = new global.window.TwoOptionalFields({});
            form.dispatchEvent(new global.window.Event('submit', { bubbles: true, cancelable: true }));
            fields.cleanup();
            expect(document.activeElement).toBe(document.querySelector('[data-two-optional-field]'));
        }
    };

    test.each([
        ['Escape returning focus to the company field', 'a control outside the panel, moved by the plugin'],
        ['a re-render restore focusing the panel', 'the Sole trader chip, moved by the plugin'],
        ['validation focusing an invalid optional field', 'another module moving it']
    ])('%s leaves the popup alone - %s', async (move) => {
        const instance = await launchWithPopupOpen();

        OWN_MOVES[move](instance);

        expect(popup.closed).toBe(false);
        expect(popup.focus).not.toHaveBeenCalled();
    });

    test('focusQuietly() clears its flag even when the focus throws', () => {
        expect(() => TwoSoleTrader.focusQuietly({ focus() { throw new Error('boom'); } })).toThrow('boom');
        expect(TwoSoleTrader._quietFocus).toBe(false);
    });
});

describe('the popup opening', () => {
    test('blurring the launching chip does not let the panel\'s own focus-out close take the panel or the flight', async () => {
        await launchWithPopupOpen();

        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(true);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(true);
        expect(document.activeElement).toBe(document.body);
    });

    test('a sibling\'s mode chip taking focus closes the popup; its click does not cancel', async () => {
        addSecondAddressBlock();
        const first = makeInstance({ companyFieldSelector: '#company-a' });
        const second = makeInstance({ companyFieldSelector: '#company-b' });
        await launchWithPopupOpen(first);
        const generation = soleTrader._enrollGeneration;

        second.openDropdown(false);
        second._registeredButton.get(0).focus();
        expect(popup.closed).toBe(true);
        second._registeredButton.trigger('click');

        expect(soleTrader.enrolling).toBe(true);
        expect(soleTrader._enrollGeneration).toBe(generation);
    });

    test('a lookup landing after Enter manually writes nothing: no session save, no publish, no recheck', async () => {
        const instance = await launchWithPopupOpen();
        const publishes = stubManager(instance);
        panelParts().notListed.trigger('click');
        panelParts().nameField.val('Typed By Hand');
        // Entering manual entry itself publishes the dropped selection; nothing may follow it.
        const before = publishes.length;
        const savesBefore = companySaves;

        soleTrader.applyBuyer(NAMED_BUYER, soleTrader._enrollGeneration);
        await settle();

        expect(companySaves).toBe(savesBefore);
        expect(publishes.length).toBe(before);
        expect(soleTrader.recheckOrderIntent).not.toHaveBeenCalled();
        expect(panelParts().nameField.val()).toBe('Typed By Hand');
        expect(soleTrader.enrolling).toBe(true);
    });

    test.each([
        [false, 'the flight still current'],
        [true, 'the flight superseded in between, which must not skip the clear']
    ])('manual entry chosen while the save is out: the capture\'s clear follows the save, nothing is published - %s', async (supersede) => {
        const instance = await launchWithPopupOpen();
        const publishes = stubManager(instance);
        const clears = jest.spyOn(instance, 'clearPersistedCompany');
        heldSaves = [];
        soleTrader.applyBuyer(NAMED_BUYER, soleTrader._enrollGeneration);
        await settle();
        expect(heldSaves.length).toBe(1);
        panelParts().notListed.trigger('click');
        if (supersede) {
            soleTrader._enrollGeneration += 1;
        }
        const clearsAtManual = clears.mock.calls.length;
        const before = publishes.length;

        heldSaves.splice(0).forEach((resolve) => resolve({ json: () => Promise.resolve({ success: true }) }));
        await settle();

        expect(clears.mock.calls.length).toBe(clearsAtManual + 1);
        expect(publishes.length).toBe(before);
        expect(soleTrader.recheckOrderIntent).not.toHaveBeenCalled();
    });

    test('a click into the panel during the mint, before any popup, latches nothing: a second chip click starts no second enrolment', async () => {
        heldMints = [];
        soleTrader.tokens = null;
        const instance = makeInstance();
        openPanel();
        const starts = jest.spyOn(soleTrader, 'startEnrollment');
        panelParts().soleTrader.trigger('click');
        await settle();
        expect(starts).toHaveBeenCalledTimes(1);

        // The query field already holds focus from the open; a chip is the in-panel control that fires a focusin.
        panelParts().registered.get(0).focus();
        expect(instance._panelKeptPastPopup).toBe(false);
        panelParts().soleTrader.trigger('click');
        expect(starts).toHaveBeenCalledTimes(1);

        releaseMints();
        await settle();
        expect(global.window.open).toHaveBeenCalledTimes(1);
    });

    test('manual entry latches for the flight: back on Registered, a late lookup still writes nothing over the hand-typed name', async () => {
        const instance = await launchWithPopupOpen();
        const publishes = stubManager(instance);
        panelParts().notListed.trigger('click');
        panelParts().nameField.val('Typed By Hand');
        openPanel();
        panelParts().registered.trigger('click');
        expect(instance._manualEntry).toBe(false);
        const before = publishes.length;
        const savesBefore = companySaves;

        soleTrader.applyBuyer(NAMED_BUYER, soleTrader._enrollGeneration);
        await settle();

        expect(companySaves).toBe(savesBefore);
        expect(publishes.length).toBe(before);
        expect(panelParts().nameField.val()).toBe('Typed By Hand');
    });

    test.each([
        ['a capture that throws when asked', true, 0, (instance) => { instance.refusesSoleTraderAdoption = () => { throw new Error('boom'); }; }],
        ['a mount forced into manual entry by an unresolvable scope', false, 1, (instance) => { instance._manualEntryForced = true; }]
    ])('%s: refuses=%s, session saves=%s', async (how, refuses, saves, arrange) => {
        const instance = await launchWithPopupOpen();
        stubManager(instance);
        arrange(instance);
        const savesBefore = companySaves;

        expect(soleTrader.captureRefusesAdoption()).toBe(refuses);
        soleTrader.applyBuyer(NAMED_BUYER, soleTrader._enrollGeneration);
        await settle();

        expect(companySaves).toBe(savesBefore + saves);
    });

    test('a nameless sole trader with no address writes nothing into the form and is still saved, published and rechecked', async () => {
        const instance = await launchWithPopupOpen();
        const publishes = stubManager(instance);
        const savesBefore = companySaves;

        soleTrader.applyBuyer(NAMELESS_BUYER, soleTrader._enrollGeneration);
        await settle();

        expect(companySaves).toBe(savesBefore + 1);
        expect(publishes.length).toBe(1);
        expect(publishes[0].companyid).toBe(NAMELESS_BUYER.organization_number);
        expect(soleTrader.recheckOrderIntent).toHaveBeenCalledTimes(1);
        expect(soleTrader.enrolling).toBe(false);
    });

    test.each([
        ['the same instance', (instance) => instance],
        ['a rebuilt instance', (instance) => { instance.destroy(); return makeInstance(); }]
    ])('a buyer lookup landing after Enter manually does not overwrite the hand-typed name on %s', async (how, adopter) => {
        const instance = await launchWithPopupOpen();
        panelParts().notListed.trigger('click');
        panelParts().nameField.val('Typed By Hand');

        expect(adopter(instance).adoptSoleTraderBuyer(NAMED_BUYER)).toBe(false);
        expect(panelParts().nameField.val()).toBe('Typed By Hand');
    });

    test('a popup opening after the buyer moved to another field during the mint is judged by the rules at open: closed, that field keeps focus, the panel closes', async () => {
        heldMints = [];
        soleTrader.tokens = null;
        makeInstance();
        openPanel();
        panelParts().soleTrader.get(0).focus();
        panelParts().soleTrader.trigger('click');
        await settle();
        expect(global.window.open).not.toHaveBeenCalled();
        const elsewhere = $("input[name='address1']").get(0);
        elsewhere.focus();

        releaseMints();
        await settle();

        expect(global.window.open).toHaveBeenCalledTimes(1);
        expect(popup.closed).toBe(true);
        expect(document.activeElement).toBe(elsewhere);
        expect(shown(panelParts().panel)).toBe(false);
        expect(soleTrader.enrolling).toBe(true);
    });

    test('a popup opening while the launching chip still holds focus blurs it and stays open', async () => {
        heldMints = [];
        soleTrader.tokens = null;
        makeInstance();
        openPanel();
        panelParts().soleTrader.get(0).focus();
        await settle();
        expect(document.activeElement).toBe(panelParts().soleTrader.get(0));

        releaseMints();
        await settle();

        expect(popup.closed).toBe(false);
        expect(document.activeElement).toBe(document.body);
        expect(shown(panelParts().panel)).toBe(true);
    });

    test('a resumed flight counts the popup as seen, so a return into the restored panel keeps it', async () => {
        document.querySelector("input[name='company']").id = 'company-field';
        const instance = await launchWithPopupOpen();
        instance.armReopen(Date.now() + 1000);
        instance.destroy();
        rerenderField('company-field');
        const replacement = makeInstance();
        replacement.restorePanelAfterRerender();
        expect(replacement._soleTraderLoading).toBe(true);

        // The restore leaves focus in the panel quietly; the Registered chip is the buyer's own return.
        panelParts().registered.get(0).focus();
        expect(popup.closed).toBe(true);
        jest.advanceTimersByTime(600);

        expect(shown(panelParts().panel)).toBe(true);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
    });


    test('a sibling restoring its panel does not take over another capture\'s popup', async () => {
        addSecondAddressBlock();
        const first = makeInstance({ companyFieldSelector: '#company-a' });
        const second = makeInstance({ companyFieldSelector: '#company-b' });
        await launchWithPopupOpen(first);

        second.armReopen(Date.now() + 1000);
        second.restorePanelAfterRerender();
        $("input[name='dni']").get(0).focus();

        expect(popup.closed).toBe(true);
        expect(first._dropdownOpen).toBe(false);
        expect(second._dropdownOpen).toBe(true);
    });

    test.each([
        ['the Sole trader chip', async () => {
            makeInstance();
            openPanel();
            // Focus itself launches (rule 1) and blurs the chip inside the focus dispatch.
            panelParts().soleTrader.get(0).focus();
            panelParts().soleTrader.trigger('click');
        }],
        ['the "Select a different sole trader" button', async () => {
            makeInstance().adoptSoleTraderBuyer(NAMED_BUYER);
            const button = document.querySelector('.two-company-select-different-sole-trader');
            button.focus();
            expect(document.activeElement).toBe(button);
            button.click();
        }]
    ])('leaves %s holding no focus, so a window return re-focuses nothing', async (launcher, launch) => {
        await launch();
        await settle();

        expect(global.window.open).toHaveBeenCalledTimes(1);
        expect(document.activeElement).toBe(document.body);
    });
});

describe('two captures on one page', () => {
    let first;
    let second;

    beforeEach(() => {
        addSecondAddressBlock();
        first = makeInstance({ companyFieldSelector: '#company-a' });
        second = makeInstance({ companyFieldSelector: '#company-b' });
    });

    test('a control outside the launching panel closes that panel and the popup; the sibling is untouched', async () => {
        await launchWithPopupOpen(first);
        const siblingClose = jest.spyOn(second, 'closeDropdown');

        document.querySelector('#company-b').focus();

        expect(popup.closed).toBe(true);
        expect(first._dropdownOpen).toBe(false);
        expect(siblingClose).not.toHaveBeenCalled();
    });

    test('the sibling\'s Sole trader chip raises the popup the other capture launched, and rule 3 closes that capture\'s panel', async () => {
        await launchWithPopupOpen(first);

        second.openDropdown(false);
        second._soleTraderButton.get(0).focus();

        expect(popup.focus).toHaveBeenCalledTimes(1);
        expect(popup.closed).toBe(false);
        expect(first._dropdownOpen).toBe(false);
    });

    test('a Tab onto the Sole trader chip raises without re-adopting a disowned enrolment; a click re-adopts', async () => {
        await launchWithPopupOpen(first);
        soleTrader.cancelEnrollment(true);
        const disowned = soleTrader._tokensGeneration;

        second.openDropdown(false);
        second._soleTraderButton.get(0).focus();
        expect(soleTrader._tokensGeneration).toBe(disowned);

        second._soleTraderButton.trigger('click');
        expect(soleTrader._tokensGeneration).toBe(soleTrader._enrollGeneration);
    });

    test('a sibling\'s popup is never recorded as this capture\'s own launch', async () => {
        await launchWithPopupOpen(first);
        panelParts().query.get(0).focus();
        expect(popup.closed).toBe(true);
        expect(first._soleTraderLoading).toBe(true);
        const firstId = reopenMemories['#company-a'].soleTraderPopup;
        popup = fakePopup();

        second.openDropdown(false);
        second._soleTraderButton.trigger('click');
        await settle();
        expect(soleTrader.isPopupOpen()).toBe(true);

        expect(reopenMemories['#company-a'].soleTraderPopup).toBe(firstId);
        expect(reopenMemories['#company-b'].soleTraderPopup).toBe(soleTrader.popupLaunchId());
    });

    test('after a re-render, the same field\'s replacement resumes the flight and a sibling restoring after it takes nothing', async () => {
        await launchWithPopupOpen(first);
        first.armReopen(Date.now() + 1000);
        first.destroy();
        rerenderField('company-a');
        const replacement = makeInstance({ companyFieldSelector: '#company-a' });
        replacement.restorePanelAfterRerender();
        second.restorePanelAfterRerender();
        const siblingClose = jest.spyOn(second, 'closeDropdown');

        expect(replacement._soleTraderLoading).toBe(true);
        expect($('#company-a').hasClass('two-company-name-loading')).toBe(true);
        expect(second._soleTraderLoading).toBe(false);
        expect(replacement._soleTraderButton.hasClass('two-company-mode-chip--selected')).toBe(true);

        $("input[name='dni']").get(0).focus();

        expect(popup.closed).toBe(true);
        expect(replacement._dropdownOpen).toBe(false);
        expect(siblingClose).not.toHaveBeenCalled();
    });

    test('a capture whose own flight settled does not resume a sibling\'s later popup after a re-render', async () => {
        await launchWithPopupOpen(first);
        panelParts().query.get(0).focus();
        jest.advanceTimersByTime(600);
        expect(first._soleTraderLoading).toBe(false);
        expect(first._dropdownOpen).toBe(true);
        popup = fakePopup();
        second.openDropdown(false);
        second._soleTraderButton.trigger('click');
        await settle();
        expect(soleTrader.isPopupOpen()).toBe(true);
        first.armReopen(Date.now() + 1000);
        second.armReopen(Date.now() + 1000);
        first.destroy();
        second.destroy();
        rerenderField('company-a');
        rerenderField('company-b');

        const firstAgain = makeInstance({ companyFieldSelector: '#company-a' });
        const secondAgain = makeInstance({ companyFieldSelector: '#company-b' });
        firstAgain.restorePanelAfterRerender();
        secondAgain.restorePanelAfterRerender();

        expect(firstAgain._dropdownOpen).toBe(true);
        expect(firstAgain._soleTraderLoading).toBe(false);
        expect(secondAgain._soleTraderLoading).toBe(true);
    });

    test('after a re-render the restored capture re-adopts the flight, so a Tab-raise then a completion is adopted', async () => {
        await launchWithPopupOpen(first);
        first.armReopen(Date.now() + 1000);
        first.destroy();
        rerenderField('company-a');
        const replacement = makeInstance({ companyFieldSelector: '#company-a' });
        const publishes = stubManager(replacement);
        replacement.restorePanelAfterRerender();
        replacement._soleTraderButton.get(0).focus();
        expect(popup.focus).toHaveBeenCalled();

        lookupBuyer = NAMED_BUYER;
        global.window.dispatchEvent(new global.window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await settle();

        expect($('#company-a').val()).toBe(NAMED_BUYER.company_name);
        expect(publishes.length).toBe(1);
    });

    test('a sibling that raised the popup resumes that flight after a re-render', async () => {
        await launchWithPopupOpen(first);
        second.openDropdown(false);
        second._soleTraderButton.trigger('click');
        expect(second._soleTraderLoading).toBe(true);
        second.armReopen(Date.now() + 1000);
        second.destroy();
        rerenderField('company-b');

        const replacement = makeInstance({ companyFieldSelector: '#company-b' });
        replacement.restorePanelAfterRerender();

        expect(replacement._soleTraderLoading).toBe(true);
    });

    test('a replacement instance restoring the panel answers for the popup the destroyed one launched: an outside control closes both', async () => {
        await launchWithPopupOpen(first);
        first.armReopen(Date.now() + 1000);
        first.destroy();
        expect(soleTrader.isPopupOpen()).toBe(true);
        const replacement = makeInstance({ companyFieldSelector: '#company-a' });
        replacement.restorePanelAfterRerender();
        expect(replacement._dropdownOpen).toBe(true);

        $("input[name='dni']").get(0).focus();

        expect(popup.closed).toBe(true);
        expect(replacement._dropdownOpen).toBe(false);
    });
});
