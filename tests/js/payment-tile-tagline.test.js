/**
 * ABN-554. The payment tile's "What is <brand>?" control is one icon wrapped in
 * a link to the brand's about page (brands/two.php `about_url`); the tooltip it
 * shows holds no anchor of its own. A brand declaring no about URL renders no
 * icon and no link at all, whatever the merchant explainer setting says. The
 * tagline beside it is gated separately, on `checkout_tagline_faq_url`.
 *
 * Rendered from the shipped `views/templates/hook/paymentinfo.tpl` via the
 * harness, so deleting the guard in the real template is what fails this.
 */

'use strict';

const { buildPaymentTileWithTagline } = require('./ps-harness');

describe('payment tile about control', () => {
    afterEach(() => {
        global.document.body.innerHTML = '';
    });

    const FAQ_URL = 'https://brand.example/faq';
    const ABOUT_URL = 'https://brand.example/what-is';

    const cases = [
        [FAQ_URL, true, ABOUT_URL, true, ABOUT_URL, 'a brand about URL renders the icon link and points it there'],
        [FAQ_URL, false, ABOUT_URL, true, null, 'the merchant explainer setting hides the control only; the tagline stays'],
        ['', true, ABOUT_URL, false, ABOUT_URL, 'no brand FAQ URL drops the tagline; the control is gated separately'],
        ['', true, '', false, null, 'a brand declaring no about URL renders no control even with the setting on'],
        ['', false, '', false, null, 'with no brand URL the merchant setting has nothing left to show'],
    ];

    cases.forEach(([faqUrl, showAboutLink, aboutUrl, taglinePresent, expectedHref, description]) => {
        test(description, () => {
            // Given brand URLs and the explainer setting; When the tile
            // renders; Then the tagline and the icon link are present or absent.
            const tile = buildPaymentTileWithTagline(faqUrl, showAboutLink, aboutUrl);
            const tagline = tile.querySelector('.two-tagline');

            if (taglinePresent) {
                expect(tagline).not.toBeNull();
                expect(tagline.textContent).toContain('Business payments made simple');
            } else {
                expect(tagline).toBeNull();
            }

            const link = tile.querySelector('a.two-info-tooltip');
            if (expectedHref === null) {
                expect(link).toBeNull();
                expect(tile.querySelector('.two-info-icon')).toBeNull();
            } else {
                expect(link).not.toBeNull();
                expect(link.getAttribute('href')).toBe(expectedHref);
                expect(link.getAttribute('target')).toBe('_blank');
                expect(link.getAttribute('rel')).toBe('noopener noreferrer');
                expect(link.getAttribute('aria-label')).toContain('What is');
                expect(link.hasAttribute('tabindex')).toBe(false);

                // The icon is decorative and the box is the described-by target.
                const icon = link.querySelector('.two-info-icon');
                expect(icon.getAttribute('aria-hidden')).toBe('true');
                const box = link.querySelector('.two-tooltip-content');
                expect(box.getAttribute('id')).toBe(link.getAttribute('aria-describedby'));
                expect(box.textContent).toContain('is a payment solution for B2B purchases online');
                expect(box.textContent).toContain('Buy now, receive your goods, pay your invoice later.');
                expect(box.textContent).toContain('Click to find out more');
                expect(box.querySelector('a')).toBeNull();
            }

            // The rest of the header is untouched either way.
            expect(tile.querySelector('.two-logo')).not.toBeNull();
        });
    });
});
