/**
 * TWO-25326 §7.3. buildCompanyIntentMessage() is the ONE place that builds the
 * intent sentence - processResult(), updateUI() and
 * TwoCheckoutManager.handleOrderIntentResult() all call it, so pinning its
 * output here covers every caller.
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
        // Mutation guard: a global replace(/%s/g, ...) would also replace a
        // literal "%s" inside the company name itself.
        const message = intent.buildCompanyIntentMessage(true, '%s Ltd', '123');
        expect(message).toBe('This order by %s Ltd (123) is likely to be accepted by Two');
    });

    test('a brand override with an extra %s placeholder degrades to literal text rather than truncating the sentence', () => {
        // fillTemplate() must not drop everything after the last placeholder it
        // has a value for.
        global.window.twopayment.intent_approved_notice = 'Approved for %s, ref %s, thanks';
        const message = intent.buildCompanyIntentMessage(true, 'Example Ltd', '556677-8899');
        expect(message).toBe('Approved for Example Ltd, ref %s, thanks');
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

describe('TwoOrderIntent.publishPayloadCompany - name/number pairing (adversarial review round 2)', () => {
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

    test('a name+number payload sets both together', () => {
        intent.publishPayloadCompany({ buyer: { company: { company_name: 'Example Ltd', organization_number: '556677-8899' } } });
        expect(intent.lastCompany).toBe('Example Ltd');
        expect(intent.lastCompanyNumber).toBe('556677-8899');
    });

    test('a later name-only payload (manual/sole-trader entry) clears the PREVIOUS company\'s retained number rather than pairing it with the new name', () => {
        // Mutation guard: assigning lastCompany and lastCompanyNumber as two
        // INDEPENDENT `if`s rather than one joint reassignment lets a stale
        // number survive alongside a freshly-selected name.
        intent.publishPayloadCompany({ buyer: { company: { company_name: 'First Holdings Ltd', organization_number: '111111-1111' } } });
        expect(intent.lastCompanyNumber).toBe('111111-1111');

        intent.publishPayloadCompany({ buyer: { company: { company_name: 'Second Holdings Ltd', organization_number: '' } } });
        expect(intent.lastCompany).toBe('Second Holdings Ltd');
        expect(intent.lastCompanyNumber).toBeNull();
    });

    test('an empty payload (no buyer/company) leaves both untouched', () => {
        intent.publishPayloadCompany({ buyer: { company: { company_name: 'Example Ltd', organization_number: '556677-8899' } } });
        intent.publishPayloadCompany({});
        expect(intent.lastCompany).toBe('Example Ltd');
        expect(intent.lastCompanyNumber).toBe('556677-8899');
    });
});
