/**
 * ABN-540: the inline merchant fee beside each "Available Payment Terms"
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
