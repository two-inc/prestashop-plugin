/**
 * ABN-554. The subtitle element is emitted only when there is something to put
 * in it: `.two-subtitle` carries a 12px bottom margin (views/css/two.css), so
 * an empty `<p>` still moves everything below it. What twopayment.php resolves
 * is already sanitised, and the template emits it unescaped, so the brand
 * fallback's inline link renders as a link rather than as its own source.
 *
 * Rendered from the shipped `views/templates/hook/paymentinfo.tpl` via the
 * harness, so deleting the guard in the real template is what fails this.
 */

'use strict';

const { buildPaymentTileWithSubtitle } = require('./ps-harness');

describe('payment tile subtitle', () => {
    afterEach(() => {
        global.document.body.innerHTML = '';
    });

    const FALLBACK =
        'For all companies, <a href="https://brand.example/faq" target="_blank" rel="noopener">read more</a>.';

    const cases = [
        ['Buy now, pay later', 'Buy now, pay later', null, 'a merchant subtitle is rendered verbatim'],
        ['0', '0', null, 'a subtitle of "0" is content, not emptiness'],
        ['', null, null, 'an empty subtitle emits no element'],
        [FALLBACK, 'For all companies, read more.', 'https://brand.example/faq', 'the brand fallback renders its link as markup'],
    ];

    cases.forEach(([subtitle, expectedText, expectedHref, description]) => {
        test(description, () => {
            // Given a resolved subtitle; When the tile renders; Then the
            // element is present with that text and link, or absent entirely.
            const tile = buildPaymentTileWithSubtitle(subtitle);
            const element = tile.querySelector('.two-subtitle');

            if (expectedText === null) {
                expect(element).toBeNull();
            } else {
                expect(element).not.toBeNull();
                expect(element.textContent.trim()).toBe(expectedText);
            }

            const link = element && element.querySelector('a');
            if (expectedHref === null) {
                expect(link).toBeFalsy();
            } else {
                expect(link.getAttribute('href')).toBe(expectedHref);
                expect(link.getAttribute('target')).toBe('_blank');
                expect(link.getAttribute('rel')).toBe('noopener');
            }

            expect(tile.querySelector('.two-payment-message')).not.toBeNull();
        });
    });
});
