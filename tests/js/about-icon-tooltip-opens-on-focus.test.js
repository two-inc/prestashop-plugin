/**
 * ABN-554 - the about icon is a keyboard destination, so its tooltip has to
 * open on focus as well as on hover, at every width. jsdom has no hover and no
 * real focus ring, so this reads the shipped stylesheet rather than a computed
 * value.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const { buildPaymentTileWithAboutControl } = require('./ps-harness');

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

    test.each(
        Array.from(STYLESHEET.matchAll(/([^{}]*two-tooltip-content[^{}]*)\{([^}]*)\}/g)).map(
            (rule) => [rule[1].replace(/\/\*[\s\S]*?\*\//g, '').trim().replace(/\s+/g, ' '), rule[2]]
        )
    )('stays in the accessibility tree under `%s`', (selector, body) => {
        expect(body).not.toMatch(/visibility:\s*hidden|display:\s*none/);
    });

    test('the closed tooltip is invisible by opacity alone', () => {
        const closed = BASE.match(/\.two-tooltip-content\s*\{([\s\S]*?)\}/)[1];

        expect(closed).toMatch(/opacity:\s*0;/);
    });

    test('the link description resolves while the tooltip is closed', () => {
        const style = global.document.createElement('style');
        style.textContent = STYLESHEET;
        global.document.head.appendChild(style);
        const tile = buildPaymentTileWithAboutControl(true, 'https://brand.example/what-is');
        const link = tile.querySelector('a.two-info-link');

        const described = global.document.getElementById(link.getAttribute('aria-describedby'));

        expect(described).not.toBeNull();
        expect(described.textContent.trim()).not.toBe('');
        expect(global.getComputedStyle(described).visibility).not.toBe('hidden');
        expect(global.getComputedStyle(described).display).not.toBe('none');
        style.remove();
        global.document.body.innerHTML = '';
    });

    test('the open tooltip takes the pointer, so a hover can travel into it', () => {
        const open = BASE.match(
            /\.two-info-tooltip:hover \.two-tooltip-content,\s*\.two-info-tooltip:focus-within \.two-tooltip-content\s*\{([\s\S]*?)\}/
        )[1];

        expect(open).toMatch(/pointer-events:\s*auto;/);
    });
});
