/**
 * TWO-25711. The payment-tile tagline and its "What is <brand>?" explainer link
 * are both brand-configured: `checkout_tagline_faq_url` in brands/two.php
 * supplies the link target, and a brand declaring none gets no tagline element
 * rather than an empty one in the flex logo row.
 *
 * Rendered from the shipped `views/templates/hook/paymentinfo.tpl` via the
 * harness, so deleting the guard in the real template is what fails this.
 */

'use strict';

const { buildPaymentTileWithTagline } = require('./ps-harness');

describe('payment tile tagline', () => {
    afterEach(() => {
        global.document.body.innerHTML = '';
    });

    const FAQ_URL = 'https://brand.example/faq';

    const cases = [
        [FAQ_URL, true, true, FAQ_URL, 'a brand URL renders the tagline and points the explainer at it'],
        [FAQ_URL, false, true, null, 'the merchant explainer setting hides the tooltip only; the tagline stays'],
        ['', true, false, null, 'a brand declaring no URL emits no tagline element at all'],
        ['', false, false, null, 'with no brand URL the merchant setting has nothing left to show'],
    ];

    cases.forEach(([faqUrl, showAboutLink, taglinePresent, expectedHref, description]) => {
        test(description, () => {
            // Given a brand FAQ URL and the explainer setting; When the tile
            // renders; Then the tagline and its link are present or absent.
            const tile = buildPaymentTileWithTagline(faqUrl, showAboutLink);
            const tagline = tile.querySelector('.two-tagline');

            if (taglinePresent) {
                expect(tagline).not.toBeNull();
                expect(tagline.textContent).toContain('Business payments made simple');
            } else {
                expect(tagline).toBeNull();
                expect(tile.querySelector('.two-info-tooltip')).toBeNull();
            }

            const link = tile.querySelector('.two-tooltip-link');
            if (expectedHref === null) {
                expect(link).toBeNull();
            } else {
                expect(link).not.toBeNull();
                expect(link.getAttribute('href')).toBe(expectedHref);
            }

            // The rest of the header is untouched either way.
            expect(tile.querySelector('.two-logo')).not.toBeNull();
        });
    });
});
