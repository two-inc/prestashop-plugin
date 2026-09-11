/**
 * TWO-25711. A merchant who clears the Subtitle field gets no subtitle element
 * at all, not an empty one: `.two-subtitle` carries a 12px bottom margin
 * (views/css/two.css), so an empty `<p>` still moves everything below it.
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

    const cases = [
        ['Buy now, pay later', 'Buy now, pay later', 'a configured subtitle is rendered verbatim'],
        ['0', '0', 'a subtitle of "0" is content, not emptiness'],
        ['', null, 'an empty subtitle emits no element'],
    ];

    cases.forEach(([subtitle, expectedText, description]) => {
        test(description, () => {
            // Given a stored subtitle; When the tile renders; Then the element
            // is present with that text, or absent entirely.
            const tile = buildPaymentTileWithSubtitle(subtitle);
            const element = tile.querySelector('.two-subtitle');

            if (expectedText === null) {
                expect(element).toBeNull();
            } else {
                expect(element).not.toBeNull();
                expect(element.textContent.trim()).toBe(expectedText);
            }

            expect(tile.querySelector('.two-payment-message')).not.toBeNull();
        });
    });
});
