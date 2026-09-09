/**
 * A brand overlay may reword the DECLINED order-intent notice or suppress it
 * entirely, on the same two keys the approved notice has carried since
 * TWO-25218. Suppression hides the text only - the order-prevention gate it
 * accompanies is functional and stays armed.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, loadScript, releaseWidgets } = require('./ps-harness');

let TwoOrderIntent;
let intent;

function buildPaymentTile() {
    document.body.innerHTML = [
        '<div class="payment-options">',
        '  <div class="payment-option">',
        "    <input type='radio' name='payment-option' value='twopayment' checked />",
        '    <div class="payment-option-content">',
        '      <span data-module-name="twopayment"></span>',
        '    </div>',
        '  </div>',
        '</div>'
    ].join('\n');
}

// A real verdict FROM Two's API: only these may be suppressed (see isRealVerdict).
function declinedVerdict() {
    return {
        approved: false,
        message: 'Two is not available for this order',
        rawResponse: { approved: false }
    };
}

function noticeText() {
    const el = document.querySelector('.two-order-intent-message');

    return el ? el.textContent : null;
}

beforeEach(() => {
    jest.resetModules();
    delete global.window.TwoOrderIntent;
    loadCompanySearch();
    global.window.twopayment = { i18n: {} };
    TwoOrderIntent = loadOrderIntent();
    intent = new TwoOrderIntent({ enabled: true });
    buildPaymentTile();
});

afterEach(() => {
    delete global.window.twopayment;
    document.body.innerHTML = '';
});

describe('declinedNoticeEnabled()', () => {
    const cases = [
        [false, false, 'an explicit false suppresses the notice'],
        [true, true, 'an explicit true enables the notice'],
        [undefined, true, 'an absent key can never mean off - an older cached JS file or template carries none'],
        [null, true, 'a null reads as enabled'],
        ['', true, 'an empty string reads as off under truthiness, so it must stay enabled'],
        [0, true, 'a zero must stay enabled'],
        ['false', true, 'the string "false" must stay enabled']
    ];

    test.each(cases)('%p -> %p (%s)', (configured, expected) => {
        global.window.twopayment.intent_declined_notice_enabled = configured;
        expect(intent.declinedNoticeEnabled()).toBe(expected);
    });
});

describe('declinedNoticeOverride()', () => {
    const cases = [
        [undefined, null, 'an absent override is the platform default copy'],
        ['', null, 'an empty override is inert, never an off switch'],
        ['   ', null, 'a whitespace-only override is inert too'],
        [42, null, 'a non-string override is the platform default copy'],
        ['No credit for %s today.', 'No credit for %s today.', 'a non-empty override is carried verbatim']
    ];

    test.each(cases)('%p -> %p (%s)', (configured, expected) => {
        global.window.twopayment.intent_declined_notice = configured;
        expect(intent.declinedNoticeOverride()).toBe(expected);
    });
});

describe('the declined sentence', () => {
    test('an override replaces the wording, name-only', () => {
        global.window.twopayment.intent_declined_notice = 'Sorry, %s cannot pay on invoice.';
        expect(intent.buildCompanyIntentMessage(false, 'Example Ltd', '556677-8899'))
            .toBe('Sorry, Example Ltd cannot pay on invoice.');
    });

    test('an override on the DECLINED key does not touch the approved sentence', () => {
        global.window.twopayment.intent_declined_notice = 'Sorry, %s cannot pay on invoice.';
        expect(intent.buildCompanyIntentMessage(true, 'Example Ltd', '556677-8899'))
            .toBe('This order by Example Ltd (556677-8899) is likely to be accepted by Two');
    });

    test('with no override the platform default copy stands', () => {
        expect(intent.buildCompanyIntentMessage(false, 'Example Ltd', ''))
            .toBe('Two is not available for this order by Example Ltd');
    });
});

describe('suppression', () => {
    test('a suppressed decline renders no element at all, not an empty wrapper', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.updateUI(declinedVerdict());
        expect(document.querySelector('.two-order-intent-message')).toBeNull();
    });

    test('a suppressed decline still arms order prevention', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        const armed = jest.spyOn(intent, 'setupOrderPrevention').mockImplementation(() => {});
        intent.updateUI(declinedVerdict());
        expect(armed).toHaveBeenCalledTimes(1);
    });

    test('a suppressed decline drops an element left over from an earlier render', () => {
        intent.updateUI(declinedVerdict());
        expect(noticeText()).toBe('Two is not available for this order');

        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.updateUI(declinedVerdict());
        expect(document.querySelector('.two-order-intent-message')).toBeNull();
    });

    test('a suppressed decline still explains a blocked submit, with the generic copy not the verdict', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.lastResult = declinedVerdict();
        intent.showOrderPreventionMessage();
        expect(noticeText()).toBe('Please resolve the payment issue before continuing.');
    });

    test('an enabled decline still writes the message on a blocked submit', () => {
        intent.lastResult = { approved: false, message: 'Two is not available for this order' };
        intent.showOrderPreventionMessage();
        expect(noticeText()).toBe('Two is not available for this order');
    });

    test('suppressing the declined notice leaves the approved notice rendering', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.updateUI({ approved: true, message: 'Your invoice is likely to be accepted', rawResponse: { approved: true } });
        expect(noticeText()).toBe('Your invoice is likely to be accepted');
    });
});

describe('technical errors are never suppressed', () => {
    // handleError() also reports approved:false. Suppressing it would bar the buyer from
    // checkout with no explanation of a problem they can actually act on.
    test('a network failure renders its message even with the declined notice off', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.handleError({ message: 'network issue' });
        expect(noticeText()).toBeTruthy();
    });

    test('a handleError result is not a real verdict', () => {
        expect(intent.isRealVerdict({ approved: false, status: 'error' })).toBe(false);
        expect(intent.isRealVerdict({ approved: false })).toBe(false);
        expect(intent.isRealVerdict({ approved: false, rawResponse: { approved: false } })).toBe(true);
    });

    test('an error result under suppression still blocks the order', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.handleError({ message: 'network issue' });
        expect(intent.lastResult.approved).toBe(false);
    });

    test('the prevention message renders for an error result under suppression', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.lastResult = { approved: false, status: 'error', message: 'network issue' };
        intent.showOrderPreventionMessage();
        expect(noticeText()).toBe('network issue');
    });
});

describe('tile state on both arms', () => {
    const cases = [
        [true, false, 'an approved verdict enables the radio'],
        [false, true, 'a declined verdict leaves the radio as the decline path set it']
    ];

    test.each(cases)('approved=%p -> radio disabled stays %p (%s)', (approved, expectDisabledUntouched) => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        global.window.twopayment.intent_approved_notice_enabled = false;
        const radio = document.querySelector('input[name="payment-option"]');
        radio.disabled = true;
        document.querySelector('.payment-option').classList.add('disabled');

        intent.updateUI({ approved: approved, message: 'x', rawResponse: { approved: approved } });

        expect(radio.disabled).toBe(expectDisabledUntouched);
        expect(document.querySelector('.payment-option').classList.contains('disabled'))
            .toBe(expectDisabledUntouched);
    });
});

describe('processResult()', () => {
    test('a suppressed decline carries no message but is still not approved', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        intent.lastCompany = 'Example Ltd';
        const result = intent.processResult({ success: true, approved: false, rawResponse: { approved: false } });
        expect(result.message).toBe('');
        expect(result.approved).toBe(false);
    });

    test('an enabled decline carries the company sentence', () => {
        intent.lastCompany = 'Example Ltd';
        const result = intent.processResult({ success: true, approved: false, rawResponse: { approved: false } });
        expect(result.message).toBe('Two is not available for this order by Example Ltd');
    });
});

describe('the checkout-manager tile render site', () => {
    let TwoCheckoutManager;
    let manager;
    let $;

    function buildTileWithMessageSection() {
        document.body.innerHTML = [
            '<div class="payment-options">',
            '  <div class="payment-option" data-module-name="twopayment">',
            "    <input type='radio' name='payment-option' value='twopayment' checked />",
            '    <div class="payment-option-content">',
            '      <div class="two-payment-container">',
            '        <section class="two-payment-info" style="display: none;">',
            '          <p class="two-subtitle"></p>',
            '          <p class="two-payment-message"></p>',
            '        </section>',
            '      </div>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n');
    }

    function section() {
        return document.querySelector('.two-payment-info');
    }

    beforeEach(() => {
        const loaded = loadCompanySearch();
        $ = loaded.$;
        loadOrderIntent();
        loadScript('views/js/modules/TwoCheckoutManager.js');
        TwoCheckoutManager = window.TwoCheckoutManager;
        global.window.twopayment = { i18n: {}, ajax_token: 'test-token' };
        buildTileWithMessageSection();
        manager = new TwoCheckoutManager({});
    });

    afterEach(() => {
        releaseWidgets($);
        delete global.window.TwoCheckoutManager_Instance;
    });

    // The tile's own render site, separate from TwoOrderIntent's inline notice: the approved
    // notice has gated in both since TWO-25218, so the declined one must too.
    const switchCases = [
        [false, false, 'an explicit false suppresses the tile decline message'],
        [true, true, 'an explicit true renders it'],
        [undefined, true, 'an absent key can never mean off'],
        ['', true, 'an empty string reads as off under truthiness, so it must stay enabled'],
        [0, true, 'a zero must stay enabled']
    ];

    test.each(switchCases)('%p -> rendered=%p (%s)', (configured, expectRendered) => {
        global.window.twopayment.intent_declined_notice_enabled = configured;
        manager.showOrderIntentDecline('Two is not available for this order');

        expect(section().querySelector('.two-payment-message').textContent)
            .toBe(expectRendered ? 'Two is not available for this order' : '');
        expect(section().classList.contains('declined')).toBe(expectRendered);
    });

    test('a suppressed tile decline hides the section rather than leaving an empty one shown', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        manager.showOrderIntentDecline('Two is not available for this order');
        expect(section().classList.contains('show')).toBe(false);
        expect(section().style.display).toBe('none');
    });

    test('a suppressed tile decline clears a message left from an earlier render', () => {
        manager.showOrderIntentDecline('Two is not available for this order');
        expect(section().querySelector('.two-payment-message').textContent)
            .toBe('Two is not available for this order');

        global.window.twopayment.intent_declined_notice_enabled = false;
        manager.showOrderIntentDecline('Two is not available for this order');
        expect(section().querySelector('.two-payment-message').textContent).toBe('');
    });

    test('a suppressed tile decline still takes the loading overlay down', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        const cleared = jest.spyOn(manager, 'clearLoadingState');
        const hidden = jest.spyOn(manager, 'hideLoadingOverlay');
        manager.showOrderIntentDecline('Two is not available for this order');
        expect(cleared).toHaveBeenCalledTimes(1);
        expect(hidden).toHaveBeenCalledTimes(1);
    });

    test('suppressing the declined notice leaves the tile approval rendering', () => {
        global.window.twopayment.intent_declined_notice_enabled = false;
        manager.showOrderIntentApproval('Your invoice is likely to be accepted');
        expect(section().querySelector('.two-payment-message').textContent)
            .toBe('Your invoice is likely to be accepted');
    });
});
