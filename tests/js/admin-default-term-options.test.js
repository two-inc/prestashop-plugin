/**
 * TWO-25705: the "Default payment terms" dropdown offers exactly the terms the
 * shop currently offers, and its selection is one of them - otherwise the
 * screen states a default the save would refuse.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { REPO_ROOT, loadAdminConfigScript, loadCompanySearch, releaseWidgets } = require('./ps-harness');

/** What twopayment.php publishes for the template's own narrowing. */
const EOM_TERM_DAYS = [30, 45, 60];

const RENDERED_TERMS = [30, 60, 90];

let $;

/**
 * The screen as the server renders it: a checkbox per offerable term in core's
 * own markup (HelperForm's template emits the FIELD's class and drops the
 * per-option one), a surcharge row per offerable term, and a default-term
 * option per term that is offered at render time - never one for a term that
 * is already unticked.
 */
function buildForm(ticked, storedDefault, customDays) {
    const checkboxes = RENDERED_TERMS.map((days) => {
        const checked = ticked.indexOf(days) !== -1 ? ' checked="checked"' : '';
        return '<div class="checkbox"><label for="PS_TWO_PAYMENT_TERMS_' + days + '">'
            + '<input type="checkbox" name="PS_TWO_PAYMENT_TERMS_' + days + '"'
            + ' id="PS_TWO_PAYMENT_TERMS_' + days + '" class="" value="1"' + checked + '>'
            + days + ' days</label></div>';
    }).join('');
    const offeredAtRender = ticked.concat(customDays && ticked.indexOf(customDays) === -1 ? [customDays] : []);
    offeredAtRender.sort((a, b) => a - b);
    const options = [''].concat(offeredAtRender).map((value) => {
        const selected = String(value) === String(storedDefault) ? ' selected' : '';
        return '<option value="' + value + '"' + selected + '>' + (value === '' ? 'Automatic' : value + ' days') + '</option>';
    }).join('');
    const rows = RENDERED_TERMS.map((days) => '<tr class="two-surcharge-row" data-term="' + days + '"></tr>').join('');

    document.body.innerHTML = `
        <div class="form-group">
            <input type="radio" name="PS_TWO_PAYMENT_TERM_TYPE" value="STANDARD" checked>
            <input type="radio" name="PS_TWO_PAYMENT_TERM_TYPE" value="EOM">
        </div>
        <div class="form-group">${checkboxes}</div>
        <div class="form-group">
            <select name="PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS">
                <option value="${customDays || ''}" selected>${customDays ? customDays + ' days' : 'Remove'}</option>
                <option value="">Remove</option>
            </select>
        </div>
        <div class="form-group">
            <select name="PS_TWO_DEFAULT_PAYMENT_TERM">${options}</select>
        </div>
        <div class="form-group">
            <select name="PS_TWO_SURCHARGE_TYPE"><option value="percentage" selected>Percentage</option></select>
            <table id="two-surcharge-grid"><tbody>${rows}</tbody></table>
            <p id="two-surcharge-empty" style="display:none;"></p>
        </div>`;
}

/** The script's initial pass is deferred by jQuery's own ready queue. */
async function awaitInitialPass() {
    for (let i = 0; i < 50; i += 1) {
        if (document.querySelector('.two-surcharge-row[data-term="60"]').style.display === 'none') {
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

function setTicks(ticked, termType) {
    $('input[name="PS_TWO_PAYMENT_TERM_TYPE"][value="' + termType + '"]').prop('checked', true);
    RENDERED_TERMS.forEach((days) => {
        $('input[name="PS_TWO_PAYMENT_TERMS_' + days + '"]').prop('checked', ticked.indexOf(days) !== -1);
    });
    $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]').trigger('change');
}

async function start(ticked, storedDefault, customDays) {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    global.twoEomTermDays = EOM_TERM_DAYS;
    global.twoCustomTermDays = customDays || 0;
    buildForm(ticked, storedDefault, customDays || 0);
    loadAdminConfigScript('updateTwoDefaultTermOptions');
    await awaitInitialPass();
}

afterEach(() => {
    if ($) {
        releaseWidgets($);
    }
    document.body.innerHTML = '';
    delete global.twoEomTermDays;
    delete global.twoCustomTermDays;
});

describe('the values the template narrows by', () => {
    // Only the server knows them: core's checkbox template drops the
    // per-option class, and the custom term has no checkbox of its own.
    test.each([
        ['the EOM day list', 'var twoEomTermDays = {$two_eom_term_days nofilter};'],
        ['the custom term day count', 'var twoCustomTermDays = {$two_custom_term_days|intval};'],
    ])('%s comes from the server, outside the literal block', (description, assignment) => {
        const tpl = fs.readFileSync(path.join(REPO_ROOT, 'views/templates/admin/configuration.tpl'), 'utf8');

        expect(tpl.split('{literal}')[0]).toContain(assignment);
    });
});

describe('the options the default-term dropdown offers', () => {
    // [description, terms ticked, term type, options left offered, selection]
    test.each([
        ['every rendered option whose term stays ticked keeps its place', [30, 90], 'STANDARD', ['', '30', '90'], '30'],
        ['unticking the stored default falls back to Automatic', [90], 'STANDARD', ['', '90'], ''],
        ['a term the term type excludes drops out too', [30, 90], 'EOM', ['', '30'], '30'],
        ['ticking a term the page rendered no option for adds none', [30, 60, 90], 'STANDARD', ['', '30', '90'], '30'],
        // getConfigurableTermSet() substitutes a fallback term for an empty
        // narrowing, so the server still renders and accepts an option here.
        ['no ticked term leaves the rendered options alone', [], 'STANDARD', ['', '30', '90'], '30'],
        ['a lone tick the term type excludes leaves them alone too', [90], 'EOM', ['', '30', '90'], '30'],
    ])('%s', async (description, ticked, termType, expectedOptions, expectedSelection) => {
        await start([30, 90], 30);

        setTicks(ticked, termType);

        expect(offeredOptions()).toEqual(expectedOptions);
        expect(selectedDefault()).toBe(expectedSelection);
    });

    test('a withdrawn term is hidden, not merely unselectable', async () => {
        await start([30, 90], 30);

        tick(30, false);

        expect(option(30).disabled).toBe(true);
        expect(option(30).style.display).toBe('none');
        expect(option(90).style.display).not.toBe('none');
    });
});

describe('the deprecated custom term', () => {
    // It is offered without a tick of its own, so a pass that judged it by the
    // checkboxes would withdraw a default the save would have kept.
    test('its option survives its own checkbox being unticked', async () => {
        await start([90], 60, 60);

        expect(offeredOptions()).toEqual(['', '60', '90']);
        expect(selectedDefault()).toBe('60');

        tick(90, false);

        expect(offeredOptions()).toEqual(['', '60']);
        expect(selectedDefault()).toBe('60');
    });

    // Removing it is a live choice in the same tab, and the save withdraws the
    // term before it reads the default.
    test('choosing Remove withdraws the option it kept alive', async () => {
        await start([90], 60, 60);

        $('select[name="PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS"]').val('').trigger('change');

        expect(offeredOptions()).toEqual(['', '90']);
        expect(selectedDefault()).toBe('');
    });
});

describe('an explicit default whose term comes back', () => {
    test('the merchant keeps the default they chose', async () => {
        await start([30, 90], 30);

        tick(30, false);
        expect(selectedDefault()).toBe('');

        tick(30, true);
        expect(selectedDefault()).toBe('30');
    });

    test('a default chosen in this edit is remembered the same way', async () => {
        await start([30, 90], 30);
        $('select[name="PS_TWO_DEFAULT_PAYMENT_TERM"]').val('90').trigger('change');

        tick(90, false);
        expect(selectedDefault()).toBe('');

        tick(90, true);
        expect(selectedDefault()).toBe('90');
    });
});
