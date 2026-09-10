/**
 * TWO-25708: with no offered term the surcharge grid has nothing to configure,
 * so the Term/Percentage/Cap headings give way to the instruction that says
 * how to get a row. Headings over an empty table read as a broken screen.
 */

'use strict';

const { loadAdminConfigScript, loadCompanySearch, releaseWidgets } = require('./ps-harness');

const EOM_CAPABLE = [30, 45, 60];

let $;

function buildForm(ticked) {
    const checkboxes = [30, 60, 90].map((days) => {
        const typeClass = EOM_CAPABLE.indexOf(days) !== -1 ? 'two-term-both' : 'two-term-standard';
        const checked = ticked.indexOf(days) !== -1 ? ' checked' : '';
        return '<input type="checkbox" class="two-term-option two-term-' + days + ' ' + typeClass + '"'
            + ' name="PS_TWO_PAYMENT_TERMS_' + days + '"' + checked + '>';
    }).join('');
    const rows = [30, 60, 90].map((days) => {
        const typeClass = EOM_CAPABLE.indexOf(days) !== -1 ? 'two-term-both' : 'two-term-standard';
        return '<tr class="two-surcharge-row ' + typeClass + '" data-term="' + days + '"></tr>';
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
            <table id="two-surcharge-grid"><thead><tr><th>Term</th></tr></thead><tbody>${rows}</tbody></table>
            <p id="two-surcharge-empty">Tick the terms you offer.</p>
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
    // 60 starts unticked so the initial pass has an observable effect.
    buildForm([30, 90]);
    loadAdminConfigScript('two-surcharge-empty');
    await awaitInitialPass();
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
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
    });

    test('the instruction never replaces the grid while a row is still offered', () => {
        $('input[name="PS_TWO_PAYMENT_TERMS_60"]').prop('checked', true).trigger('change');

        expect(row(60).style.display).not.toBe('none');
        expect(isVisible('#two-surcharge-grid')).toBe(true);
        expect(isVisible('#two-surcharge-empty')).toBe(false);
    });
});
