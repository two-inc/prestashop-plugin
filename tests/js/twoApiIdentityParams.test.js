#!/usr/bin/env node
'use strict';

/**
 * Exercises twoApiIdentityParams() - the shared client/client_v/merchant
 * query-param builder every direct browser->Two API call reuses - in
 * isolation from the rest of TwoCompanySearch.js (no jQuery/DOM deps).
 * Self-contained: only Node built-ins, mirroring tests/run.php's
 * no-framework convention.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(
    path.join(__dirname, '..', '..', 'views', 'js', 'modules', 'TwoCompanySearch.js'),
    'utf8'
);
const match = source.match(/function twoApiIdentityParams\([\s\S]*?\n}/);
if (!match) {
    throw new Error('twoApiIdentityParams() not found in TwoCompanySearch.js');
}

function callWith(twopayment, extra) {
    const sandbox = { window: { twopayment } };
    vm.createContext(sandbox);
    vm.runInContext(match[0], sandbox);
    return sandbox.twoApiIdentityParams(extra);
}

function assertSame(expected, actual, message) {
    const e = JSON.stringify(expected);
    const a = JSON.stringify(actual);
    if (e !== a) {
        throw new Error(`${message}: expected ${e}, got ${a}`);
    }
}

const cases = [
    [undefined, undefined, { client: 'PS', client_v: '', merchant: '' }, 'missing window.twopayment falls back to safe defaults'],
    [{ client: 'PS', client_v: '2.4.0', merchant: 'acme' }, undefined, { client: 'PS', client_v: '2.4.0', merchant: 'acme' }, 'reflects the JsDef-supplied identity'],
    [{ client: 'PS', client_v: '2.4.0', merchant: 'acme' }, { q: 'foo' }, { client: 'PS', client_v: '2.4.0', merchant: 'acme', q: 'foo' }, 'merges call-specific params alongside identity'],
    [{ client: 'PS', client_v: '2.4.0', merchant: 'acme' }, { merchant: 'spoofed' }, { client: 'PS', client_v: '2.4.0', merchant: 'spoofed' }, 'call-specific params take precedence on key collision'],
];

let failed = 0;
for (const [twopayment, extra, expected, description] of cases) {
    try {
        assertSame(expected, callWith(twopayment, extra), description);
        console.log(`PASS ${description}`);
    } catch (e) {
        failed++;
        console.error(`FAIL ${description}: ${e.message}`);
    }
}

if (failed > 0) {
    process.exit(1);
}
console.log('All tests passed.');
