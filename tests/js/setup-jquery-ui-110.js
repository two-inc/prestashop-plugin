/**
 * Pick the older jQuery UI for this Jest project. Read by ps-harness at
 * require time, so it has to be set before any test file loads.
 */

'use strict';

process.env.JQUERY_UI = '1.10';
