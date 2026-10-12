/**
 * The inline merchant fee beside each "Available Payment Terms"
 * checkbox. An empty span reads as "this term carries no fee", so a term the
 * answer did not price must be labelled instead of left blank.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { REPO_ROOT, loadCompanySearch, stubAjax, releaseWidgets } = require('./ps-harness');

/**
 * jQuery defers its ready callback, and how far depends on the document's
 * readyState, so wait for the fee request itself rather than for a fixed
 * number of ticks.
 */
async function awaitFeeRequest(ajax) {
    for (let i = 0; i < 50 && !ajax.last(); i += 1) {
        await new Promise(function (resolve) {
            setTimeout(resolve, 1);
        });
    }
    const call = ajax.last();
    if (!call) {
        throw new Error('the fee loader never issued its request');
    }
    return call;
}

const NO_FIGURE = 'no figure';
const FEES_URL = 'https://shop.example.test/admin/two-fee-rates';

/**
 * The admin JS ships inside the Smarty template, so the test runs the real
 * shipped source: the {literal} block is plain JS once its tags are dropped.
 */
function loadAdminConfigScript() {
    const tpl = fs.readFileSync(path.join(REPO_ROOT, 'views/templates/admin/configuration.tpl'), 'utf8');
    const block = tpl.split('{literal}')[1];
    if (!block) {
        throw new Error('configuration.tpl: no {literal} block found');
    }
    const source = block.split('{/literal}')[0].replace(/<\/?script[^>]*>/g, '');
    if (!/loadTwoMerchantFees/.test(source)) {
        throw new Error('configuration.tpl: {literal} block does not carry the fee loader');
    }
    const indirectEval = eval;
    indirectEval(source);
}

function buildTermsForm(terms) {
    document.body.innerHTML = terms
        .map(function (days) {
            return (
                '<div class="form-group">'
                + '<input type="checkbox" name="PS_TWO_PAYMENT_TERMS_' + days + '" checked>'
                + ' <span class="two-term-fee text-muted" data-term="' + days + '"></span>'
                + '</div>'
            );
        })
        .join('');
}

function feeText(days) {
    return document.querySelector('.two-term-fee[data-term="' + days + '"]').textContent;
}

let $;
let ajax;

beforeEach(() => {
    $ = loadCompanySearch().$;
    ajax = stubAjax($);
    global.twoMerchantFeeRatesUrl = FEES_URL;
    global.twoVerifyApiKeyUrl = '';
    global.twoRefreshMerchantUrl = '';
    global.twoFeeNoFigureText = NO_FIGURE;
    ['twoApiKeyCheckingText', 'twoApiKeyVerifiedText', 'twoApiKeyFailedText', 'twoRefreshMerchantBusyText',
        'twoRefreshMerchantFailedText', 'twoFeesStaleText', 'twoFeesStaleDatedText', 'twoFeesUnavailableText',
        'twoFeesNoApiKeyText'].forEach(function (name) {
        global[name] = name;
    });
});

afterEach(() => {
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
});

describe('inline merchant fee per payment term', () => {
    // description, answered fees, expected span text per term
    const cases = [
        [
            'every requested term priced',
            { 15: { percentage: 1.5, fixed: 0 }, 30: { percentage: 2.51, fixed: 0.1 } },
            { 15: '(1.50%)', 30: '(2.51% + 0.10 NOK)' }
        ],
        [
            'a term the answer did not price is labelled, not left blank',
            { 30: { percentage: 2.51, fixed: 0.1 } },
            { 15: '(' + NO_FIGURE + ')', 30: '(2.51% + 0.10 NOK)' }
        ],
        [
            'an answer pricing nothing labels every term',
            {},
            { 15: '(' + NO_FIGURE + ')', 30: '(' + NO_FIGURE + ')' }
        ],
        [
            'a zero fee draws as zero, which is not the same as unpriced',
            { 15: { percentage: 0, fixed: 0 }, 30: { percentage: 0, fixed: 0.25 } },
            { 15: '(0.00 NOK)', 30: '(0.25 NOK)' }
        ]
    ];

    test.each(cases)('%s', async (description, fees, expected) => {
        buildTermsForm([15, 30]);
        loadAdminConfigScript();

        const call = await awaitFeeRequest(ajax);
        expect(call.url).toBe(FEES_URL);
        call.succeed({ success: true, currency: 'NOK', fees: fees });

        Object.keys(expected).forEach(function (days) {
            expect(feeText(days)).toBe(expected[days]);
        });
    });
});

/**
 * TWO-26305: a fetch the API refused (4xx) says why, in the API's own words
 * and drawn as text; anything else keeps the generic wording. The notice sits
 * in the controls column, under the checkboxes, not after the label column.
 */
describe('fee notice for a failed fetch', () => {
    const REJECTED = 'Refused with HTTP %1$s: %2$s. Missing, not zero.';
    const REJECTED_BARE = 'Refused with HTTP %s. Missing, not zero.';
    const PAYOUT = "payout_schedule could not be inferred from the merchant's payee accounts";

    beforeEach(() => {
        global.twoFeesRejectedText = REJECTED;
        global.twoFeesRejectedBareText = REJECTED_BARE;
    });

    /** The core HelperForm checkbox group: label column, then controls column. */
    function buildHelperFormTerms(terms) {
        document.body.innerHTML = '<div class="form-group">'
            + '<label class="control-label col-lg-3">Available Payment Terms</label>'
            + '<div class="col-lg-9">'
            + terms.map(function (days) {
                return '<div class="checkbox"><label for="PS_TWO_PAYMENT_TERMS_' + days + '">'
                    + '<input type="checkbox" name="PS_TWO_PAYMENT_TERMS_' + days + '" checked> ' + days + ' days'
                    + ' <span class="two-term-fee text-muted" data-term="' + days + '"></span></label></div>';
            }).join('')
            + '</div></div>';
    }

    // description, answer, expected notice text
    const cases = [
        [
            'a 4xx shows the status and the API message',
            { success: false, error: 'rejected', http_status: 400, message: PAYOUT },
            'Refused with HTTP 400: ' + PAYOUT + '. Missing, not zero.'
        ],
        [
            'a trailing full stop in the API message is not doubled',
            { success: false, error: 'rejected', http_status: 422, message: 'net_terms invalid. ' },
            'Refused with HTTP 422: net_terms invalid. Missing, not zero.'
        ],
        [
            'a message the server cut keeps its ellipsis',
            { success: false, error: 'rejected', http_status: 400, message: 'a long message cut...' },
            'Refused with HTTP 400: a long message cut... Missing, not zero.'
        ],
        [
            'markup in the API message is drawn as text',
            { success: false, error: 'rejected', http_status: 400, message: '<img src=x onerror=alert(1)>' },
            'Refused with HTTP 400: <img src=x onerror=alert(1)>. Missing, not zero.'
        ],
        [
            'replacement patterns in the API message are kept literally',
            { success: false, error: 'rejected', http_status: 400, message: 'cost $& more' },
            'Refused with HTTP 400: cost $& more. Missing, not zero.'
        ],
        [
            'a 4xx with no message still names the status',
            { success: false, error: 'rejected', http_status: 404, message: '' },
            'Refused with HTTP 404. Missing, not zero.'
        ],
        [
            'a network error or 5xx keeps the generic wording',
            { success: false, error: 'upstream' },
            'twoFeesUnavailableText'
        ],
        ['no API key keeps its own wording', { success: false, error: 'not_configured' }, 'twoFeesNoApiKeyText']
    ];

    test.each(cases)('%s', async (description, answer, expected) => {
        buildHelperFormTerms([15, 30]);
        loadAdminConfigScript();

        const call = await awaitFeeRequest(ajax);
        call.succeed(answer);

        const notices = document.querySelectorAll('.two-term-fee-notice');
        expect(notices.length).toBe(1);
        expect(notices[0].textContent).toBe(expected);
        expect(notices[0].querySelector('img')).toBeNull();
    });

    // description, stale answer, expected notice text
    const staleCases = [
        [
            'stored figures after a 4xx say why, dated',
            { error: 'rejected', http_status: 400, message: PAYOUT, fetched_at_display: '2026-10-01 09:00' },
            'Stale from 2026-10-01 09:00. Refused stale with HTTP 400: ' + PAYOUT + '.'
        ],
        [
            'stored figures after a 4xx say why, undated',
            { error: 'rejected', http_status: 403, message: 'not allowed.' },
            'twoFeesStaleText Refused stale with HTTP 403: not allowed.'
        ],
        [
            'stored figures after a 4xx with no message name the status',
            { error: 'rejected', http_status: 404, message: '' },
            'twoFeesStaleText Refused stale with HTTP 404.'
        ],
        ['stored figures after a generic failure add no reason', {}, 'twoFeesStaleText']
    ];

    test.each(staleCases)('%s', async (description, extra, expected) => {
        global.twoFeesStaleDatedText = 'Stale from %s.';
        global.twoFeesStaleRejectedText = 'Refused stale with HTTP %1$s: %2$s.';
        global.twoFeesStaleRejectedBareText = 'Refused stale with HTTP %s.';
        buildHelperFormTerms([15, 30]);
        loadAdminConfigScript();

        const call = await awaitFeeRequest(ajax);
        call.succeed(Object.assign({
            success: true, stale: true, currency: 'NOK', fees: { 15: { percentage: 1.5, fixed: 0 } }
        }, extra));

        expect(document.querySelector('.two-term-fee-notice').textContent).toBe(expected);
        expect(feeText(15)).toBe('(1.50%)');
    });

    test('the notice sits in the controls column, under the checkboxes', async () => {
        buildHelperFormTerms([15, 30]);
        loadAdminConfigScript();

        const call = await awaitFeeRequest(ajax);
        call.succeed({ success: false, error: 'rejected', http_status: 400, message: PAYOUT });

        const notice = document.querySelector('.two-term-fee-notice');
        expect(notice.parentElement.className).toBe('col-lg-9');
        expect(notice.previousElementSibling.className).toBe('checkbox');
    });

    test('an AJAX failure keeps the generic wording', async () => {
        buildHelperFormTerms([15, 30]);
        loadAdminConfigScript();

        const call = await awaitFeeRequest(ajax);
        call.fail('error');

        expect(document.querySelector('.two-term-fee-notice').textContent).toBe('twoFeesUnavailableText');
    });
});
