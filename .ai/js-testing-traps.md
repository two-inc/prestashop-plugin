---
description: "Limits of jsdom and tab listings for verifying browser behaviour, and the dual jQuery UI Jest projects"
---

# JS Testing Traps

Moved verbatim from `AGENTS.md`.

## A Popup Window Is In No Tab Listing

`window.open` returns a window outside a browser extension's tab group, so a tab
list can never answer "did the popup open" — nor can a hang. The authoritative check
is the page's own retained handle and its `.closed`, which means wrapping
`window.open` before the action that should raise one. Judging from a tab list
yields a confident false "no window opened".

## Keyboard Behaviour Is Not Verifiable In jsdom

jsdom implements no sequential focus navigation: a dispatched `Tab` keydown moves
focus nowhere, so no `make test-js` suite can observe a focus trap, a wrong tab
order or a reverse-Tab dead end, however many cases it carries and however green it
is. Assert the observable proxies — the parts are one contiguous run in document
order, nothing inside the panel carries a non-negative `tabindex` it should not,
the handler leaves the `Tab` event undefaulted — and verify the keyboard behaviour
itself in a real browser. A passing jsdom Tab test is never evidence that a trap is
absent.

Three more traps in the JS suites:

- **A real chip click fires no `focusin`.** The chip's `mousedown` handler calls
  `preventDefault()`, which suppresses the native focus, so a rule written only
  against `focusin` never sees a pointer buyer at all.
- **A keydown performs no default action.** No character is inserted and no
  `beforeinput`/`input` follows, so a suite can only assert where a capture PUT
  the character, never that the browser would have put it there itself.
- **jsdom's `getElementById` answers with the first-REGISTERED node, not the
  tree-first one**, so a fixture carrying a duplicate id silently resolves to the
  wrong element.
- **A mutation proves NEW coverage only when re-run against the base ref.** One the
  existing suite already catches proves the suite is sensitive, not that the case
  added covers anything.

## Every JS Spec Runs On Both jQuery UI Versions

A PrestaShop theme decides which jQuery UI the module runs on, so `make test-js` runs
every spec twice, as the two Jest projects `jquery-ui-1.14` and `jquery-ui-1.10`. The
1.10 project sets `JQUERY_UI=1.10` through `tests/js/setup-jquery-ui-110.js` and
`ps-harness.js` installs that widget instead. 1.10 is the oldest version a theme still
serves, 1.14 the newest.

The two differ in both API surface and the markup a row is rendered with, so a suite
pinned to one says nothing about shops on the other — a spec written against 1.14 can
pass with and without the fix it exists to hold. A new company-search spec passes on
both projects, and a behaviour that differs between the versions is handled in the
module rather than accommodated in the test.
