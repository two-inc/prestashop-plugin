/**
 * Jest config for the module's browser JS.
 *
 * Mirrors magento-plugin's Test/Js/jest.config.js: config lives next to the
 * tests, rootDir points back at the repo root so tests can read the shipped
 * source files by their real repo-relative paths, and jsdom supplies the
 * document that jQuery and jQuery UI need.
 */

module.exports = {
    rootDir: '../..',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    testEnvironment: 'jsdom'
};
