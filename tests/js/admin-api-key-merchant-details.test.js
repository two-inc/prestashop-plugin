/**
 * TWO-26232: the inline API key check shows the merchant the key resolves to
 * as soon as the check completes, without a Save. A key Two rejected hides the
 * merchant panel; a check that judged nothing about the key leaves it alone.
 */

'use strict';

const { loadAdminConfigScript, loadCompanySearch, stubAjax, releaseWidgets } = require('./ps-harness');

const VERIFY_URL = 'https://shop.example.test/admin/two-verify-api-key';

let $;
let ajax;

function buildForm(panelShown) {
    document.body.innerHTML = `
        <div id="two-merchant-panel"${panelShown ? '' : ' style="display:none;"'}>
            <div id="two-merchant-id">saved-id</div>
            <div id="two-merchant-short-name">saved</div>
            <div id="two-merchant-env">staging</div>
        </div>
        <select name="PS_TWO_ENVIRONMENT"><option value="production" selected>production</option></select>
        <input type="text" name="PS_TWO_MERCHANT_API_KEY" value="">`;
}

/** jQuery defers the script's ready callback, so blur until the check fires. */
async function blurUntilChecked() {
    for (let i = 0; i < 50; i += 1) {
        $('input[name="PS_TWO_MERCHANT_API_KEY"]').val('a-fresh-key').trigger('blur');
        const call = ajax.calls.find((c) => c.url === VERIFY_URL);
        if (call) {
            return call;
        }
        await new Promise((resolve) => setTimeout(resolve, 1));
    }
    throw new Error('the inline key check never issued its request');
}

/** What the merchant panel shows, or null while it is hidden. */
function shown() {
    const panel = document.getElementById('two-merchant-panel');
    if (panel.style.display === 'none') {
        return null;
    }
    return ['two-merchant-id', 'two-merchant-short-name', 'two-merchant-env']
        .map((id) => document.getElementById(id).textContent)
        .join(' | ');
}

beforeEach(() => {
    $ = loadCompanySearch().$;
    ajax = stubAjax($);
    global.twoVerifyApiKeyUrl = VERIFY_URL;
    global.twoMerchantFeeRatesUrl = '';
    global.twoRefreshMerchantUrl = '';
    ['twoApiKeyCheckingText', 'twoApiKeyVerifiedText', 'twoApiKeyFailedText', 'twoRefreshMerchantBusyText',
        'twoRefreshMerchantFailedText', 'twoFeeNoFigureText', 'twoFeesStaleText', 'twoFeesStaleDatedText',
        'twoFeesUnavailableText', 'twoFeesNoApiKeyText'].forEach(function (name) {
        global[name] = name;
    });
});

afterEach(() => {
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
});

describe('merchant details follow the inline API key check', () => {
    test.each([
        { panelShown: true, response: { ok: true, merchant_id: 'new-id', merchant_short_name: 'fresh' },
            expected: 'new-id | fresh | production', description: 'a verified key replaces the saved merchant before Save' },
        { panelShown: false, response: { ok: true, merchant_id: 'new-id', merchant_short_name: 'fresh' },
            expected: 'new-id | fresh | production', description: 'a verified key reveals a panel hidden at render' },
        { panelShown: true, response: { ok: false, definitive: true, message: 'rejected' },
            expected: null, description: 'a rejected key hides the merchant' },
        { panelShown: true, response: { ok: false, definitive: false, message: 'unreachable' },
            expected: 'saved-id | saved | staging', description: 'an inconclusive check leaves the merchant as it was' },
    ])('$description', async ({ panelShown, response, expected, description }) => {
        buildForm(panelShown);
        loadAdminConfigScript('runTwoApiKeyLiveCheck');

        (await blurUntilChecked()).succeed(response);

        expect({ description, shown: shown() }).toEqual({ description, shown: expected });
    });
});
