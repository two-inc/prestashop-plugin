/**
 * Jest config for the module's browser JS.
 *
 * Config lives next to the tests, rootDir points back at the repo root so
 * tests can read the shipped source files by their real repo-relative paths,
 * and jsdom supplies the document that jQuery and jQuery UI need.
 *
 * Every spec runs twice, once per jQuery UI, because a PrestaShop theme decides
 * which one the module runs on and the versions differ in both API surface and
 * rendered markup: a hook that exists only on the newer one passes a suite
 * pinned to it while being inert on every shop serving the older one. 1.10 is
 * the oldest a theme still serves, 1.14 the newest.
 */

const path = require('path');

// Absolute: a project entry resolves a relative `rootDir` against the parent
// config's, which would apply the same two levels twice.
const shared = {
    rootDir: path.resolve(__dirname, '../..'),
    testEnvironment: 'jsdom',
    // The suite restores its own spies and stubs by hand; these are the net for
    // the next test that forgets to, since a leaked spy on Date.now or on an
    // instance method fails somewhere other than where it was created.
    restoreMocks: true,
    resetMocks: true
};

module.exports = {
    projects: [
        Object.assign({}, shared, {
            displayName: 'jquery-ui-1.14',
            testMatch: ['<rootDir>/tests/js/**/*.test.js']
        }),
        Object.assign({}, shared, {
            displayName: 'jquery-ui-1.10',
            setupFiles: ['<rootDir>/tests/js/setup-jquery-ui-110.js'],
            testMatch: ['<rootDir>/tests/js/**/*.test.js']
        })
    ]
};
