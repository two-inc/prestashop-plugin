# Browser JS test suite

Jest + jsdom over the module's front-office JavaScript in `views/js/`.

```bash
make test-js            # from the module root; needs host Node 20+
npm run test:js         # equivalent, if node_modules is already installed
```

CI gates this as the `jest` job in `.github/workflows/tests.yml`. It is a real gate, not
`continue-on-error`.

The layout mirrors `magento-plugin`'s `Test/Js/`: a `package.json` at the repo root whose
only purpose is to hold JS devDependencies (`package-release.sh` already excluded
`package.json` and `package-lock.json` from the release zip), a jest config sitting next to the tests with `rootDir` pointed back
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

- `responseCallback` fires **exactly once** per search on all twelve paths: short term, success,
  timeout, network error, parser error, a failure carrying no textStatus at all, abort,
  superseded-by-a-newer-search, backspacing under the minimum while a request is live, a
  stale success that outran its abort, a stale failure, and teardown mid-search. Zero calls leaks the spinner; two lets a superseded result
  overwrite a live one.
- a failure is reported as `unavailable`, never as an empty result set — an empty dropdown
  reads to the buyer as "my company is not registered".
- an abort is `silent`; a timeout is not.
- request envelope: 30s timeout (clear of the API's own `stop_after_delay(10)` retry
  window), `limit`/`offset` bounding, the country parameter and its upper-casing,
  `withCredentials: false` on this cross-origin call, the `beforeSend` guard that makes it
  impossible to attach credential headers, and the handle being released once settled.
- `degraded === true` handling: degraded-with-no-results reads as unavailable,
  degraded-with-results still renders them but is flagged so the caller does not cache a
  known-partial list; an absent field means false, and so does every truthy non-`true`
  value.
- organisation-number extraction across the payload shapes different registries use.
- the cache: outlives the instance that filled it, keys on the country so two countries
  never share results, expires after five minutes, and evicts oldest-first at fifty
  entries. The key/wire invariant is asserted directly as well as through each resolution
  case: whatever `getCurrentCountry()` returns reaches both the cache key and the `country`
  parameter, so neither can believe in a country the other does not. The one place they
  could still fork is a country change *during* a request, since the key is built before
  the request and closed over — pinned too: the entry stays filed under the country the
  request actually carried.
- country resolution, which decides which register is searched: the `data-iso-code`
  attribute, then the server-supplied `window.twopayment.countries` id-to-ISO map (built
  from this shop's own country table), then an exact match on the option's visible text.
  When none resolves, `getCurrentCountry()` returns `''` and **no search is made** —
  reported as `{ countryUnresolved: true }`, rendered as a "pick a country" row rather
  than an empty list, and never cached. It used to guess instead: `navigator.language`
  and then a literal `'GB'`, so a shop the chain could not read searched GB companies for
  every buyer, silently, forever. Both guesses are pinned as gone, the locale one with a
  stub since jsdom's `en-US` default would otherwise hide it. Omitting the parameter is
  not an alternative — `country` is required on `GET /companies/v2/company`, so an
  omitted one is a 422.

  The source-level half of that lives in the PHP suite
  (`tests/CompanySearchCountrySourcingSpec.php`): that the map is still injected, that the
  i18n key the JS reads still exists under that name, and that neither guess has come
  back. Reachable-in-jsdom behaviour is only a subset — a re-added `'GB'` on a path no
  test builds a DOM for would pass every Jest case here.

`company-search-rerender.test.js` — what happens when PrestaShop re-renders the address
form (which it does for something as ordinary as a country change):

- a company selected through the real widget (menu focus + select, not a direct
  `onCompanySelected()` call) lands in `company`, `companyid`, `dni` and `vat_number`; a
  lookup-id-only company gets its number and address from the detail endpoint; the
  address-lookup toggle stops the address fill without stopping `companyid`; and selecting
  the unavailable row writes nothing.
- the spinner always comes back down — success, timeout, empty, degraded — and a
  superseded search leaves it up for the request that replaced it.
- the `_renderItem` patch is applied once per widget instance and is still the same
  function *by reference* after 100 re-setups, 20 country changes and 20
  `updatedAddressForm` events; a genuinely new widget is patched again.
- the unavailable row renders non-selectable (`ui-state-disabled`, `aria-disabled`) and as
  text, and `select`/`focus` refuse it.
- a destroyed instance cannot act: `setupAutocomplete()` leaves the replaced field alone,
  `onCompanySelected()` returns false and writes no `companyid`/`dni`, the
  `updatedAddressForm` handler stands down (asserted on the guard as well as the outcome,
  since two sibling guards would otherwise mask it), the country change listener is
  unbound — from the selector it actually bound, not just the default one — and a pending
  country-listener retry never fires. The `onCompanySelected` case has a mirror-image test
  proving a *live* instance in the same position still does the work.
- `destroy()` tears the custom dropdown down in its own `try`, so a throwing jQuery UI
  bridge cannot leave live listeners bound on an instance already marked destroyed.
- a stale organisation number is cleared across a re-render: a matching pair survives,
  a differing name or an absent selection marker clears it, and the comparison ignores
  case and whitespace because themes and server round-trips both reflow them.
- the submit hook restores `dni`/`vat_number` from `companyid` without overwriting a value
  the buyer typed, and a buyer-typed DNI becomes the organisation number when none was
  selected.
- the company-detail fill: the request carries `withCredentials: false` in its own right
  (the search endpoint's twin being correct says nothing about this one), the number is
  read out of six payload shapes and overrides a divergent search number, a
  BUSINESS/REGISTERED/VISITING address wins over an untyped or mailing one whatever the
  order, four address key-variant spellings normalise, a partial address writes the parts
  it has, and a failed detail lookup leaves the selection intact.
- which values a partial address is allowed to clear: a field the buyer typed survives, a
  field a *previous* company's fill wrote is cleared (and announces the clear to the
  theme), a buyer edit on top of an autofill turns it back into buyer input, and a value
  two successive companies happen to share is still recognised as autofilled by the third.
  The load-bearing one is a company *confirming* a value the buyer had already typed:
  there is no write to do, but the marker still has to be recorded, or the next company
  that lacks that field reads it as untouched buyer input and one company's address sticks
  to another for the rest of checkout.
- the custom fallback used when jQuery UI is absent: its own spinner clears on failure,
  survives a superseded request, and repeated setup leaves exactly one dropdown rather
  than orphan containers listening on the shared field.
- the in-field spinner **element** (TWO-25288). The spinner is drawn in CSS now rather than
  being a background GIF on the input, so it is a real `<span>` sibling with a DOM lifecycle
  of its own — it can be inserted twice, orphaned by a re-render, or end up on the wrong
  side of the fallback dropdown, none of which a background-image could do. Pinned on
  **both** render paths, because they share the CSS contract but not a line of the code that
  arms it: exactly one spinner after ten repeated setups, ten country changes, a whole-form
  re-render and a theme that swaps only the input; `destroy()` removing the element and the
  containing-block class, not just the classes; no `transform` resolving on the element,
  since the animation owns that property and would overwrite `translateY(-50%)` centring on
  its first frame; and the animation not being stepped — the spokes have a 30deg period, so
  `steps(12, end)` maps the pattern onto itself and renders a spinner that never moves,
  which a test asserting only the keyframes *name* would have passed.

  Two cases pin that the span is still **wired** after an address-form re-render, not merely
  present — one per render path. This is the exposure that comes with owning the element: the
  span is inserted by us into markup the *platform* re-renders, so the failure mode is a span
  that survives as dead decoration — present, unduplicated, correctly placed, driven by
  nothing — which every count-and-position assertion passes. The only proof is driving a real
  search against the re-rendered form and watching `display` change. Verified by mutation:
  inserting the span outside the input's parent reddens both, while leaving the module's own
  loading-state code untouched.

  Nothing stubs the loading state. The harness replaces only `$.ajax` (the network) and the
  `prestashop` event bus; jQuery, the jQuery UI autocomplete widget and the module source are
  all real, so the class toggling that drives the spinner is unstubbed production code on both
  paths.

  One case exists specifically to pin the **general** sibling combinator: the fallback path
  inserts its dropdown container directly after the input, so on that path the spinner is
  not the input's adjacent sibling and a `+` selector would match the container instead —
  spinner permanently invisible on that path, every class assertion still green.

  These are the only tests here that load a **stylesheet** (`installStylesheet()` in the
  harness). Visibility is decided entirely by a sibling selector keyed off the loading class
  on the input, so asserting on classes and on the element's presence would pass with a
  spinner that is permanently invisible. They read `getComputedStyle(...).display` against
  the real `views/css/two.css` instead. Note that jsdom cascades by **document order alone**,
  with no specificity: the stylesheet's `display` rules are ordered so that order and
  specificity give the same answer, and a `display` declaration added to the spinner's
  appearance rule would pass here while being wrong in a browser.

## Known gaps

Deliberately out of scope for this suite, which covers company-search resilience and
re-render safety rather than every behaviour in the file: the order-intent recheck
(`shouldDeferIntentTrigger` and both `triggerOrderIntentRecheck` call sites),
`getCurrentCountry()`'s option-text and id-map strategies, and
`persistCompanyToCookie`. Mutating any of those leaves the suite green.

`teardownCustomAutocomplete()`'s `blur`-listener unbind is unobservable: the handler's
closure re-hides only the list it was created with, and that node is already detached by
the time a leaked handler could fire, so removing the unbind changes nothing any test can
see. The `input`-listener unbind beside it is covered (via the duplicate-search count).

Two branches of `clearStaleOrganizationSelection()` are redundant rather than untested —
removing either changes no observable outcome for any reachable input: the absent-marker
branch (the name-mismatch comparison below it reaches the same result) and the
`!orgNumber` early return (with no number there is nothing for the branches below to
clear). Likewise `normalizeCompanyName()`'s `.trim()`, since both call sites pass
pre-trimmed values.

## Adding tests

Prefer driving behaviour through the real widget (`field.autocomplete('instance').search(term)`)
over calling internals, and settle requests explicitly through `stubAjax()` — out-of-order
responses, aborts and timeouts are the subject matter here, so controlling the timing is
the point rather than a shortcut.
