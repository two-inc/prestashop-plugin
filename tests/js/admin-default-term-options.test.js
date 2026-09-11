/**
 * TWO-25705: the "Default payment terms" dropdown offers exactly the terms the
 * shop currently offers, and its selection is one of them - otherwise the
 * screen states a default the save would refuse.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { REPO_ROOT, loadAdminConfigScript, loadCompanySearch, releaseWidgets } = require('./ps-harness');

/** What twopayment.php publishes for the template's own EOM narrowing. */
const EOM_TERM_DAYS = [30, 45, 60];


let $;

function buildForm(ticked, storedDefault) {
    // Core's own checkbox markup: HelperForm's template emits the FIELD's
    // class and drops the per-option one, so nothing on the input says which
    // term type the term belongs to.
    const checkboxes = [30, 60, 90].map((days) => {
        const checked = ticked.indexOf(days) !== -1 ? ' checked="checked"' : '';
        return '<div class="checkbox"><label for="PS_TWO_PAYMENT_TERMS_' + days + '">'
            + '<input type="checkbox" name="PS_TWO_PAYMENT_TERMS_' + days + '"'
            + ' id="PS_TWO_PAYMENT_TERMS_' + days + '" class="" value="1"' + checked + '>'
            + days + ' days</label></div>';
    }).join('');
    const options = ['', 30, 60, 90].map((value) => {
        const selected = String(value) === String(storedDefault) ? ' selected' : '';
        return '<option value="' + value + '"' + selected + '>' + (value === '' ? 'Automatic' : value + ' days') + '</option>';
    }).join('');

    document.body.innerHTML = `
        <div class="form-group">
            <input type="radio" name="PS_TWO_PAYMENT_TERM_TYPE" value="STANDARD" checked>
            <input type="radio" name="PS_TWO_PAYMENT_TERM_TYPE" value="EOM">
        </div>
        <div class="form-group">${checkboxes}</div>
        <div class="form-group">
            <select name="PS_TWO_DEFAULT_PAYMENT_TERM">${options}</select>
        </div>
        <div class="form-group">
            <select name="PS_TWO_SURCHARGE_TYPE"><option value="percentage" selected>Percentage</option></select>
            <table id="two-surcharge-grid">
                <tbody>
                    <tr class="two-surcharge-row" data-term="30"></tr>
                    <tr class="two-surcharge-row" data-term="60"></tr>
                    <tr class="two-surcharge-row" data-term="90"></tr>
                </tbody>
            </table>
            <p id="two-surcharge-empty" style="display:none;"></p>
        </div>`;
}

/** The script's initial pass is deferred by jQuery's own ready queue. */
async function awaitInitialPass() {
    for (let i = 0; i < 50; i += 1) {
        if (option(60).disabled) {
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, 1));
    }
    throw new Error('the admin script never ran its initial pass');
}

function option(value) {
    return document.querySelector('select[name="PS_TWO_DEFAULT_PAYMENT_TERM"] option[value="' + value + '"]');
}

function offeredOptions() {
    return Array.from(document.querySelectorAll('select[name="PS_TWO_DEFAULT_PAYMENT_TERM"] option'))
        .filter((node) => !node.disabled)
        .map((node) => node.value);
}

function selectedDefault() {
    return $('select[name="PS_TWO_DEFAULT_PAYMENT_TERM"]').val();
}

function tick(days, on) {
    $('input[name="PS_TWO_PAYMENT_TERMS_' + days + '"]').prop('checked', on).trigger('change');
}

beforeEach(async () => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    global.twoEomTermDays = EOM_TERM_DAYS;
    // 60 starts unticked so the initial pass has an observable effect.
    buildForm([30, 90], 30);
    loadAdminConfigScript('updateTwoDefaultTermOptions');
    await awaitInitialPass();
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete global.twoEomTermDays;
});

describe('the EOM day list the template narrows by', () => {
    // Only the server knows it: core's checkbox template drops the per-option
    // class, so the day counts have to arrive as their own published value.
    test('the template takes it from the server, outside the literal block', () => {
        const tpl = fs.readFileSync(path.join(REPO_ROOT, 'views/templates/admin/configuration.tpl'), 'utf8');
        const outer = tpl.split('{literal}')[0];

        expect(outer).toContain('var twoEomTermDays = {$two_eom_term_days nofilter};');
    });
});

describe('the options the default-term dropdown offers', () => {
    // [description, terms ticked after the initial pass, term type, options left offered, selection]
    test.each([
        ['an unticked term drops out of the dropdown', [30, 90], 'STANDARD', ['', '30', '90'], '30'],
        ['unticking the stored default falls back to Automatic', [60, 90], 'STANDARD', ['', '60', '90'], ''],
        ['every term ticked offers every term', [30, 60, 90], 'STANDARD', ['', '30', '60', '90'], '30'],
        ['no term ticked leaves Automatic alone', [], 'STANDARD', [''], ''],
        ['a term the term type excludes drops out too', [30, 60, 90], 'EOM', ['', '30', '60'], '30'],
    ])('%s', (description, ticked, termType, expectedOptions, expectedSelection) => {
        $('input[name="PS_TWO_PAYMENT_TERM_TYPE"][value="' + termType + '"]').prop('checked', true);
        [30, 60, 90].forEach((days) => {
            $('input[name="PS_TWO_PAYMENT_TERMS_' + days + '"]').prop('checked', ticked.indexOf(days) !== -1);
        });
        $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]').trigger('change');

        expect(offeredOptions()).toEqual(expectedOptions);
        expect(selectedDefault()).toBe(expectedSelection);
    });

    test('a withdrawn term is hidden, not merely unselectable', () => {
        expect(option(60).disabled).toBe(true);
        expect(option(60).style.display).toBe('none');
        expect(option(30).style.display).not.toBe('none');
    });
});

describe('an explicit default whose term comes back', () => {
    test('the merchant keeps the default they chose', () => {
        tick(30, false);
        expect(selectedDefault()).toBe('');

        tick(30, true);
        expect(selectedDefault()).toBe('30');
    });

    test('a default chosen in this edit is remembered the same way', () => {
        tick(60, true);
        $('select[name="PS_TWO_DEFAULT_PAYMENT_TERM"]').val('60').trigger('change');

        tick(60, false);
        expect(selectedDefault()).toBe('');

        tick(60, true);
        expect(selectedDefault()).toBe('60');
    });
});
