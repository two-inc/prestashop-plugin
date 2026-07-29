# Browser JS test suite

Jest + jsdom over the module's front-office JavaScript in `views/js/`.

```bash
make test-js            # from the module root
npm run test:js         # equivalent, if node_modules is already installed
```

CI gates this as the `jest` job in `.github/workflows/tests.yml`. It is a real gate, not
`continue-on-error`.

The layout mirrors `magento-plugin`'s `Test/Js/`: a `package.json` at the repo root whose
only purpose is to hold JS devDependencies (it is excluded from the release zip by
`package-release.sh`), a jest config sitting next to the tests with `rootDir` pointed back
at the repo root, and `testEnvironment: 'jsdom'`.

Files glob — unlike `tests/run.php`, a new `*.test.js` needs no registration.

## How the browser gets stood up

`views/js/modules/*.js` are plain classic scripts: they declare a class and hang it off
`window`, and they are loaded by a Smarty template into a page where jQuery, jQuery UI and
PrestaShop's own `prestashop` event bus already exist as globals. There is nothing to
`require()` and nothing to import.

So `ps-harness.js` assembles the real environment rather than mocking it:

- jsdom (Jest's `testEnvironment`) supplies `window` and `document`;
- the **real** jQuery and the **real** jQuery UI autocomplete widget are installed onto
  that window as devDependencies;
- `prestashop` is a small stub — it is a PrestaShop-supplied event bus with no npm
  distribution. Note it deliberately has no `off`, because the real one has none either,
  which is the reason `TwoCompanySearch` has to defend itself with a `_destroyed` flag
  rather than unregistering its handler;
- the module source is then evaluated in global scope, exactly as a `<script>` tag would.

**No production code was refactored to make this testable.** The scripts load as-is.

Using the real widget instead of a mock is deliberate. Two of the three company-search
defects these tests exist to pin are properties *of jQuery UI*, not of our code:

- the widget bridge does **not** build a fresh instance when `.autocomplete({...})` is
  called on an already-initialised field — it runs `option()` + `_init()` on the existing
  one, so a `_renderItem` wrapper applied on every setup nests a layer deeper per
  address-form update until rendering a row blows the stack;
- it clears `ui-autocomplete-loading` only when a search's `response()` callback actually
  runs, because that is where it decrements `pending` — so a dropped callback leaks the
  spinner for the rest of the session.

A hand-written mock would have to reproduce both behaviours correctly to catch either bug,
which is precisely the assumption that let them ship in the first place.

`jquery-ui`'s distributed files are AMD-or-browser-globals with no CommonJS branch, so
under Jest each falls through to `factory(jQuery)` and does *not* pull its own
dependencies. The harness therefore requires them in dependency order by hand — the same
load order a theme's `<script>` tags produce.

## What is covered

`company-search-resilience.test.js` — `searchCompanies()` and the class-static result
cache:

- `responseCallback` fires **exactly once** per search on every path: short term, success,
  timeout, network/parser error, abort, superseded-by-a-newer-search, a stale success that
  outran its abort, a stale failure, and teardown mid-search. Zero calls leaks the
  spinner; two lets a superseded result overwrite a live one.
- a failure is reported as `unavailable`, never as an empty result set — an empty dropdown
  reads to the buyer as "my company is not registered".
- an abort is `silent`; a timeout is not.
- request envelope: 30s timeout (clear of the API's own `stop_after_delay(10)` retry
  window), `limit`/`offset` bounding, country parameter, and the `beforeSend` guard that
  makes it impossible to attach credential headers to the public API call.
- `degraded === true` handling: degraded-with-no-results reads as unavailable,
  degraded-with-results still renders them but is flagged so the caller does not cache a
  known-partial list; an absent field means false, and so does every truthy non-`true`
  value.
- organisation-number extraction across the payload shapes different registries use.
- the cache: outlives the instance that filled it, keeps an unselected country distinct
  from an explicit one, expires on TTL, and evicts oldest-first at its bound.

`company-search-rerender.test.js` — what happens when PrestaShop re-renders the address
form (which it does for something as ordinary as a country change):

- the spinner always comes back down — success, timeout, empty, degraded — and a
  superseded search leaves it up for the request that replaced it.
- the `_renderItem` patch is applied once per widget instance and is byte-for-byte the
  same function after 100 re-setups, 20 country changes and 20 `updatedAddressForm`
  events; a genuinely new widget is patched again.
- the unavailable row renders non-selectable (`ui-state-disabled`, `aria-disabled`) and as
  text, and `select`/`focus` refuse it.
- a destroyed instance cannot act: `setupAutocomplete()` leaves the replaced field alone,
  `onCompanySelected()` returns false and writes no `companyid`/`dni`, the
  `updatedAddressForm` handler stands down, and a pending country-listener retry never
  fires. Each has a mirror-image test proving a *live* instance in the same position still
  does the work.
- the custom fallback used when jQuery UI is absent: its own spinner clears on failure,
  survives a superseded request, and repeated setup leaves exactly one dropdown rather
  than orphan containers listening on the shared field.

## Adding tests

Prefer driving behaviour through the real widget (`field.autocomplete('instance').search(term)`)
over calling internals, and settle requests explicitly through `stubAjax()` — out-of-order
responses, aborts and timeouts are the subject matter here, so controlling the timing is
the point rather than a shortcut.
