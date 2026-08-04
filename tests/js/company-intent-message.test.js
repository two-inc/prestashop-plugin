/**
 * Coverage for TwoOrderIntent.buildCompanyIntentMessage() (TWO-25326 §7.3,
 * 2026-08-03 design ruling): the standalone tile company-name/number label
 * is gone, and the captured company is folded directly into the intent
 * sentence instead. This is the ONE place that builds that sentence -
 * processResult(), updateUI() and TwoCheckoutManager.handleOrderIntentResult()
 * all call it, so pinning its output here covers every caller.
 */

const { loadOrderIntent } = require('./ps-harness');

describe('TwoOrderIntent.buildCompanyIntentMessage', () => {
    let TwoOrderIntent;
    let intent;

    beforeEach(() => {
        jest.resetModules();
        delete global.window.TwoOrderIntent;
        global.window.twopayment = { i18n: {} };
        TwoOrderIntent = loadOrderIntent();
        intent = new TwoOrderIntent({ enabled: true });
    });

    afterEach(() => {
        delete global.window.twopayment;
    });

    test('approved, with number: exact wording, name and number both present', () => {
        const message = intent.buildCompanyIntentMessage(true, 'Example Ltd', '556677-8899');
        expect(message).toBe('This order by Example Ltd (556677-8899) is likely to be accepted by Two');
    });

    test('declined, with number: exact wording, name and number both present', () => {
        const message = intent.buildCompanyIntentMessage(false, 'Example Ltd', '556677-8899');
        expect(message).toBe('Two is not available for this order by Example Ltd (556677-8899)');
    });

    test('approved, no number: name-only fallback, no empty parentheses', () => {
        const message = intent.buildCompanyIntentMessage(true, 'Example Ltd', '');
        expect(message).toBe('This order by Example Ltd is likely to be accepted by Two');
        expect(message).not.toContain('(');
    });

    test('declined, no number: name-only fallback, no empty parentheses', () => {
        const message = intent.buildCompanyIntentMessage(false, 'Example Ltd', '');
        expect(message).toBe('Two is not available for this order by Example Ltd');
        expect(message).not.toContain('(');
    });

    test('declined, number is null/undefined: treated the same as no number', () => {
        expect(intent.buildCompanyIntentMessage(false, 'Example Ltd', null))
            .toBe('Two is not available for this order by Example Ltd');
        expect(intent.buildCompanyIntentMessage(false, 'Example Ltd', undefined))
            .toBe('Two is not available for this order by Example Ltd');
    });

    test('a name containing "%s" is not treated as a template token - only ONE substitution per placeholder', () => {
        // Mutation guard: a naive global replace (replace(/%s/g, ...)) would
        // also replace a literal "%s" that happened to be inside the company
        // name itself. `.replace('%s', x)` (first-match-only) must not do that.
        const message = intent.buildCompanyIntentMessage(true, '%s Ltd', '123');
        expect(message).toBe('This order by %s Ltd (123) is likely to be accepted by Two');
    });

    test('brand override (TWO-25218) is honoured for approved messages, name-only', () => {
        global.window.twopayment.intent_approved_notice = 'Zakelijk krediet voor %s is beschikbaar';
        const message = intent.buildCompanyIntentMessage(true, 'Example Ltd', '556677-8899');
        expect(message).toBe('Zakelijk krediet voor Example Ltd is beschikbaar');
    });

    test('brand override does not apply to declined messages', () => {
        global.window.twopayment.intent_approved_notice = 'Zakelijk krediet voor %s is beschikbaar';
        const message = intent.buildCompanyIntentMessage(false, 'Example Ltd', '556677-8899');
        expect(message).toBe('Two is not available for this order by Example Ltd (556677-8899)');
    });

    test('falls back to the built-in default wording when window.twopayment.i18n has no entry', () => {
        global.window.twopayment = { i18n: {} };
        expect(intent.buildCompanyIntentMessage(true, 'Example Ltd', '123'))
            .toBe('This order by Example Ltd (123) is likely to be accepted by Two');
        expect(intent.buildCompanyIntentMessage(false, 'Example Ltd', '123'))
            .toBe('Two is not available for this order by Example Ltd (123)');
    });

    test('reads the PHP-supplied translated strings when present', () => {
        global.window.twopayment.i18n.invoice_likely_accepted_for = 'Translated approved for %s (%s)';
        global.window.twopayment.i18n.invoice_cannot_be_approved_for = 'Translated declined for %s (%s)';
        expect(intent.buildCompanyIntentMessage(true, 'Example Ltd', '123'))
            .toBe('Translated approved for Example Ltd (123)');
        expect(intent.buildCompanyIntentMessage(false, 'Example Ltd', '123'))
            .toBe('Translated declined for Example Ltd (123)');
    });
});
