/**
 * ABN-554 (QA recommendation R9). The chip row advertises itself to assistive
 * technology as a radio group, so it owes the W3C radio-group keyboard
 * contract: one tab stop for the whole group, and arrow keys that move the
 * checked selection within it.
 *
 * jsdom cannot prove the tab-stop count (it has no sequential focus
 * navigation) or the focus ring (it has no layout); those are verified in a
 * real browser. What it can pin is the roving tabindex the tab stop is made
 * of, the traversal itself, and the coalescing of the persist request.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    stubAjax,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';
const TERMS = [14, 30, 45, 60];
const INITIAL_TERM = 30;
/** Matches KEYBOARD_PERSIST_DELAY_MS in initializePaymentTerms(). */
const PERSIST_DELAY_MS = 250;

let TwoCheckoutManager;
let $;
let ajax;

function buildTermsContainer() {
    document.body.innerHTML = [
        '<div class="two-term-chips">',
        '  <div class="two-term-chips__container" id="two-terms-chips"></div>',
        '  <div class="two-terms-selected">',
        '    <span class="two-terms-selected-days" id="two-selected-days"></span>',
        '  </div>',
        '</div>'
    ].join('\n');
}

function makeChips(terms, initialTerm) {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: false,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token',
        available_payment_terms: terms,
        default_payment_term: initialTerm
    });
    buildTermsContainer();
    manager.initializePaymentTerms();
    return manager;
}

function chips() {
    return Array.prototype.slice.call(document.querySelectorAll('.two-term-chip'));
}

function chipFor(days) {
    return document.querySelector('.two-term-chip[data-days="' + days + '"]');
}

function tabbableDays() {
    return chips()
        .filter((chip) => chip.getAttribute('tabindex') === '0')
        .map((chip) => Number(chip.dataset.days));
}

function checkedDays() {
    return chips()
        .filter((chip) => chip.getAttribute('aria-checked') === 'true')
        .map((chip) => Number(chip.dataset.days));
}

function selectedByClassDays() {
    return chips()
        .filter((chip) => chip.classList.contains('two-term-chip--selected'))
        .map((chip) => Number(chip.dataset.days));
}

function press(key, modifier) {
    const event = new window.KeyboardEvent('keydown', Object.assign(
        { key: key, bubbles: true, cancelable: true },
        modifier ? { [modifier]: true } : {}
    ));
    document.activeElement.dispatchEvent(event);
    return event;
}

function termSaves() {
    return ajax.calls.filter((call) => call.settings.data && call.settings.data.action === 'savePaymentTerm');
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    ajax = stubAjax($);
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;
});

afterEach(async () => {
    ajax.calls.forEach((call) => {
        if (!call.aborted) {
            try {
                call.fail('abort', 'abort');
            } catch (e) {
                // some call sites wire .done()/.fail() directly - see other suites
            }
        }
    });
    await flushPromises();
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    jest.restoreAllMocks();
});

describe('arrow-key traversal of the term chips', () => {
    test.each([
        [['ArrowRight'], 45, 'right moves to the next term'],
        [['ArrowDown'], 45, 'down moves to the next term'],
        [['ArrowLeft'], 14, 'left moves to the previous term'],
        [['ArrowUp'], 14, 'up moves to the previous term'],
        [['ArrowLeft', 'ArrowLeft'], 60, 'left off the first term wraps to the last'],
        [['ArrowRight', 'ArrowRight', 'ArrowRight'], 14, 'right off the last term wraps to the first'],
        [['Home'], 14, 'Home jumps to the first term'],
        [['End'], 60, 'End jumps to the last term'],
        [['ArrowRight', 'Home'], 14, 'Home jumps from wherever the traversal reached'],
        [['ArrowLeft', 'End'], 60, 'End jumps from wherever the traversal reached']
    ])('%s selects %s - %s', (keys, expected) => {
        makeChips(TERMS, INITIAL_TERM);
        chipFor(INITIAL_TERM).focus();

        keys.forEach((key) => press(key));

        expect(checkedDays()).toEqual([expected]);
        expect(selectedByClassDays()).toEqual([expected]);
        expect(tabbableDays()).toEqual([expected]);
        expect(document.activeElement).toBe(chipFor(expected));
        expect(document.querySelector('#two-selected-days').textContent).toContain(String(expected));
    });

    test.each([
        ['altKey', 'ArrowLeft', 'Alt+Left is the browser back shortcut'],
        ['ctrlKey', 'ArrowRight', 'Ctrl+arrow is a word-wise caret movement'],
        ['metaKey', 'ArrowDown', 'Meta+arrow is a platform shortcut']
    ])('%s+%s is left to the browser - %s', (modifier, key) => {
        makeChips(TERMS, INITIAL_TERM);
        chipFor(INITIAL_TERM).focus();

        const event = press(key, modifier);

        expect(checkedDays()).toEqual([INITIAL_TERM]);
        expect(document.activeElement).toBe(chipFor(INITIAL_TERM));
        expect(event.defaultPrevented).toBe(false);
    });

    test('an unhandled key is left to the browser', () => {
        makeChips(TERMS, INITIAL_TERM);
        chipFor(INITIAL_TERM).focus();

        const event = press('Tab');

        expect(checkedDays()).toEqual([INITIAL_TERM]);
        expect(event.defaultPrevented).toBe(false);
    });

    test('a handled key does not also scroll the page', () => {
        makeChips(TERMS, INITIAL_TERM);
        chipFor(INITIAL_TERM).focus();

        expect(press('ArrowDown').defaultPrevented).toBe(true);
    });
});

describe('the group as one tab stop', () => {
    test('exactly one chip is tabbable, and it is the checked one', () => {
        makeChips(TERMS, INITIAL_TERM);

        expect(tabbableDays()).toEqual([INITIAL_TERM]);
        expect(checkedDays()).toEqual([INITIAL_TERM]);
    });

    test('the tab stop follows a clicked chip', () => {
        makeChips(TERMS, INITIAL_TERM);

        chipFor(60).click();

        expect(tabbableDays()).toEqual([60]);
        expect(checkedDays()).toEqual([60]);
        expect(selectedByClassDays()).toEqual([60]);
    });

    test('a selection matching no offered chip still leaves the group tabbable', () => {
        makeChips(TERMS, INITIAL_TERM);

        chips().forEach((chip) => {
            chip.dataset.days = String(Number(chip.dataset.days) + 1000);
        });
        chipFor(1014).click();

        expect(tabbableDays()).toEqual([1014]);
    });
});

describe('a single offered term', () => {
    test('is a disabled chip the arrow keys leave alone', () => {
        makeChips([30], 30);

        const chip = chipFor(30);
        expect(chips().length).toBe(1);
        expect(chip.disabled).toBe(true);
        expect(chip.getAttribute('aria-disabled')).toBe('true');
        expect(checkedDays()).toEqual([30]);

        chip.focus();
        const event = press('ArrowRight');

        expect(checkedDays()).toEqual([30]);
        expect(event.defaultPrevented).toBe(false);
    });
});

describe('the persisted selection', () => {
    beforeEach(() => {
        jest.useFakeTimers();
        window.twopayment = {
            order_intent_url: ORDER_INTENT_URL,
            ajax_token: 'test-token'
        };
    });

    afterEach(() => {
        jest.useRealTimers();
    });

    test('an arrow sweep costs one request, not one per key', () => {
        makeChips(TERMS, INITIAL_TERM);
        chipFor(INITIAL_TERM).focus();

        press('ArrowRight');
        press('ArrowRight');
        press('ArrowRight');
        expect(termSaves().length).toBe(0);

        jest.advanceTimersByTime(PERSIST_DELAY_MS);

        expect(termSaves().length).toBe(1);
        expect(termSaves()[0].settings.data.days).toBe(14);
    });

    test('a click persists straight away', () => {
        makeChips(TERMS, INITIAL_TERM);

        chipFor(45).click();

        expect(termSaves().length).toBe(1);
        expect(termSaves()[0].settings.data.days).toBe(45);
    });

    test('a click cancels a pending keyboard persist', () => {
        makeChips(TERMS, INITIAL_TERM);
        chipFor(INITIAL_TERM).focus();

        press('ArrowRight');
        chipFor(14).click();
        jest.advanceTimersByTime(PERSIST_DELAY_MS);

        expect(termSaves().length).toBe(1);
        expect(termSaves()[0].settings.data.days).toBe(14);
    });
});
