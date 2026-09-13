/**
 * ABN-554 - the about icon is a keyboard destination, so its tooltip has to
 * open on focus as well as on hover, at every width. jsdom has no hover and no
 * real focus ring, so this reads the shipped stylesheet rather than a computed
 * value.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const STYLESHEET = fs.readFileSync(
    path.join(__dirname, '..', '..', 'views', 'css', 'two.css'),
    'utf8'
);

/** Rules outside any media query, and the narrow-width block that re-states the open transform. */
const BASE = STYLESHEET.slice(0, STYLESHEET.indexOf('@media'));
const MOBILE = STYLESHEET.slice(STYLESHEET.indexOf('@media (max-width: 768px)'));

describe('the about tooltip', () => {
    test.each([
        ['opens on hover', BASE, /\.two-info-tooltip:hover\s+\.two-tooltip-content/],
        ['opens on keyboard focus', BASE, /\.two-info-tooltip:focus-within\s+\.two-tooltip-content/],
        ['sits where it belongs on hover at mobile widths', MOBILE, /\.two-info-tooltip:hover\s+\.two-tooltip-content/],
        ['sits where it belongs on focus at mobile widths', MOBILE, /\.two-info-tooltip:focus-within\s+\.two-tooltip-content/]
    ])('it %s', (description, source, pattern) => {
        expect(source).toMatch(pattern);
    });

    test('the open tooltip takes the pointer, so a hover can travel into it', () => {
        const open = BASE.match(
            /\.two-info-tooltip:hover \.two-tooltip-content,\s*\.two-info-tooltip:focus-within \.two-tooltip-content\s*\{([\s\S]*?)\}/
        )[1];

        expect(open).toMatch(/pointer-events:\s*auto;/);
    });
});
