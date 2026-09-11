/**
 * TWO-25708: with no offered term the surcharge grid has nothing to configure,
 * so the Term/Percentage/Cap headings give way to the instruction that says
 * how to get a row. Headings over an empty table read as a broken screen.
 */

'use strict';

const { loadAdminConfigScript, loadCompanySearch, releaseWidgets } = require('./ps-harness');

/** What twopayment.php publishes for the template's own EOM narrowing. */
const EOM_TERM_DAYS = [30, 45, 60];


let $;

function buildForm(ticked) {
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
    const rows = [30, 60, 90].map((days) => {
        return '<tr class="two-surcharge-row" data-term="' + days + '"></tr>';
    }).join('');

    document.body.innerHTML = `
        <div class="form-group">
            <input type="radio" name="PS_TWO_PAYMENT_TERM_TYPE" value="STANDARD" checked>
            <input type="radio" name="PS_TWO_PAYMENT_TERM_TYPE" value="EOM">
        </div>
        <div class="form-group">${checkboxes}</div>
        <div class="form-group">
            <select name="PS_TWO_DEFAULT_PAYMENT_TERM"><option value="" selected>Automatic</option></select>
        </div>
        <div class="form-group">
            <select name="PS_TWO_SURCHARGE_TYPE"><option value="percentage" selected>Percentage</option></select>
            <table id="two-surcharge-grid"><thead><tr><th>Term</th><th class="two-col-cap">Cap</th></tr></thead><tbody>${rows}</tbody></table>
            <p id="two-surcharge-empty" class="help-block">No payment term is available to surcharge.</p>
            <p class="help-block two-col-cap">The cap applies to the whole fee.</p>
        </div>`;
}

/** The script's initial pass is deferred by jQuery's own ready queue. */
async function awaitInitialPass() {
    for (let i = 0; i < 50; i += 1) {
        if (row(60).style.display === 'none') {
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, 1));
    }
    throw new Error('the admin script never ran its initial pass');
}

function row(days) {
    return document.querySelector('.two-surcharge-row[data-term="' + days + '"]');
}

function isVisible(selector) {
    return document.querySelector(selector).style.display !== 'none';
}

beforeEach(async () => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    global.twoEomTermDays = EOM_TERM_DAYS;
    // 60 starts unticked so the initial pass has an observable effect.
    buildForm([30, 90]);
    loadAdminConfigScript('two-surcharge-empty');
    await awaitInitialPass();
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete global.twoEomTermDays;
});

describe('the surcharge grid against the offered terms', () => {
    // [description, terms ticked, term type, grid on screen, instruction on screen]
    test.each([
        ['an offered term keeps the grid on screen', [30, 90], 'STANDARD', true, false],
        ['no ticked term replaces the headings with the instruction', [], 'STANDARD', false, true],
        ['a ticked term the term type excludes offers no row either', [90], 'EOM', false, true],
        ['a ticked EOM-eligible term keeps the grid on screen', [30], 'EOM', true, false],
        ['ticking a term again brings the grid back', [30, 60, 90], 'STANDARD', true, false],
    ])('%s', (description, ticked, termType, gridVisible, instructionVisible) => {
        $('input[name="PS_TWO_PAYMENT_TERM_TYPE"][value="' + termType + '"]').prop('checked', true);
        [30, 60, 90].forEach((days) => {
            $('input[name="PS_TWO_PAYMENT_TERMS_' + days + '"]').prop('checked', ticked.indexOf(days) !== -1);
        });
        $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]').trigger('change');

        expect(isVisible('#two-surcharge-grid')).toBe(gridVisible);
        expect(isVisible('#two-surcharge-empty')).toBe(instructionVisible);
        expect(isVisible('p.two-col-cap')).toBe(gridVisible);
    });

    test('the instruction never replaces the grid while a row is still offered', () => {
        $('input[name="PS_TWO_PAYMENT_TERMS_60"]').prop('checked', true).trigger('change');

        expect(row(60).style.display).not.toBe('none');
        expect(isVisible('#two-surcharge-grid')).toBe(true);
        expect(isVisible('#two-surcharge-empty')).toBe(false);
    });
});
