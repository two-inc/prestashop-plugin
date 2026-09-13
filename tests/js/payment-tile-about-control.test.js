/**
 * ABN-554. The payment tile's "What is <brand>?" control is one icon wrapped in
 * a link to the brand's about page (brands/two.php `about_url`); the tooltip is
 * the link's sibling and holds no anchor of its own. A brand declaring no about
 * URL renders no icon and no link at all, whatever the merchant explainer
 * setting says.
 *
 * Rendered from the shipped `views/templates/hook/paymentinfo.tpl` via the
 * harness, so deleting the guard in the real template is what fails this.
 */

'use strict';

const { buildPaymentTileWithAboutControl, ABOUT_TOOLTIP_ID } = require('./ps-harness');

describe('payment tile about control', () => {
    afterEach(() => {
        global.document.body.innerHTML = '';
    });

    const ABOUT_URL = 'https://brand.example/what-is';

    const cases = [
        [true, ABOUT_URL, ABOUT_URL, 'a brand about URL renders the icon link and points it there'],
        [false, ABOUT_URL, null, 'the merchant explainer setting hides the control'],
        [true, '', null, 'a brand declaring no about URL renders no control even with the setting on'],
        [false, '', null, 'with no brand URL the merchant setting has nothing left to show'],
    ];

    cases.forEach(([showAboutLink, aboutUrl, expectedHref, description]) => {
        test(description, () => {
            // Given a brand about URL and the explainer setting; When the tile
            // renders; Then the icon link is present or absent.
            const tile = buildPaymentTileWithAboutControl(showAboutLink, aboutUrl);

            const wrapper = tile.querySelector('.two-info-tooltip');
            if (expectedHref === null) {
                expect(wrapper).toBeNull();
                expect(tile.querySelector('.two-info-icon')).toBeNull();
            } else {
                const link = wrapper.querySelector('a.two-info-link');
                expect(link).not.toBeNull();
                expect(link.getAttribute('href')).toBe(expectedHref);
                expect(link.getAttribute('target')).toBe('_blank');
                expect(link.getAttribute('rel')).toBe('noopener');
                expect(link.getAttribute('aria-label')).toContain('What is');
                expect(link.hasAttribute('tabindex')).toBe(false);

                // The icon is the shared SVG the other platforms ship, and is
                // decorative: the link carries the name.
                const icon = link.querySelector('img.two-info-icon');
                expect(icon.getAttribute('src')).toContain('views/img/question.svg');
                expect(icon.getAttribute('alt')).toBe('');

                // The box is the described-by target, and is the link's sibling.
                const box = wrapper.querySelector(':scope > .two-tooltip-content');
                expect(box.getAttribute('id')).toBe(ABOUT_TOOLTIP_ID);
                expect(box.getAttribute('role')).toBe('tooltip');
                // Closed by opacity alone, so without this the same prose is
                // read twice: once as the link's description, once in flow.
                expect(box.getAttribute('aria-hidden')).toBe('true');
                expect(link.getAttribute('aria-describedby')).toBe(ABOUT_TOOLTIP_ID);
                expect(box.textContent).toContain('is a payment solution for B2B purchases online');
                expect(box.textContent).toContain('Buy now, receive your goods, pay your invoice later.');
                expect(box.textContent).toContain('Click to find out more');
                expect(box.querySelector('a')).toBeNull();

                const emphasis = box.querySelector('.two-tooltip-text--strong');
                expect(emphasis.tagName).toBe('STRONG');

                // The icon sits beside the logo, which is this tile's title.
                expect(tile.querySelector('.two-logo').compareDocumentPosition(wrapper))
                    .toBe(global.Node.DOCUMENT_POSITION_FOLLOWING);
            }

            // The rest of the header is untouched either way.
            expect(tile.querySelector('.two-logo')).not.toBeNull();
        });
    });
});
