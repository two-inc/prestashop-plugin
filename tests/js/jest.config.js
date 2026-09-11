/**
 * Jest config for the module's browser JS.
 *
 * Config lives next to the tests, rootDir points back at the repo root so
 * tests can read the shipped source files by their real repo-relative paths,
 * and jsdom supplies the document that jQuery and jQuery UI need.
 */

module.exports = {
    rootDir: '../..',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    testEnvironment: 'jsdom',
    // The suite restores its own spies and stubs by hand; these are the net for
    // the next test that forgets to, since a leaked spy on Date.now or on an
    // instance method fails somewhere other than where it was created.
    restoreMocks: true,
    resetMocks: true
};
