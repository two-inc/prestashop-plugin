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

`company-summary.test.js` — the read-only company display in the payment tile
(`TwoCompanySummary`, TWO-25288):

- the three capture modes onto the two slots: **search** and **sole trader** show name and
  number, **manual entry** shows the name with the number slot blank. Blank and *present*,
  asserted separately — a slot that disappears reads as a rendering fault rather than as an
  answer.
- a number whose `data-two-company-name` tag no longer matches the field is **not** shown
  beside the new name.
- the number arriving only with the company details (the GB shape) repaints the slot.
- the display is not editable: **no** `input` / `select` / `textarea` / `button` /
  `contenteditable` anywhere in the block, both slots are `SPAN`s with no `name`, and no part
  of the block is inside a `form`.
- the hidden `companyid` input still carries the number into the submission, inside the
  address form, exactly one of it, unchanged by 25 renders — and a sole-trader push does not
  invent one. The identifier mirroring the selection performs is re-pinned here too, because
  this change added a call into the middle of `onCompanySelected()`.
- the tile is repainted after a `updatedCart` re-render replaces the block, that the repaint
  is **deferred** rather than synchronous with the event, and that a second instance does not
  stack bus handlers (the bus has no `off`, so a per-instance registration leaks silently —
  only one instance is built today, which is exactly what would have hidden it). `cleanup()`
  stops the instance listening and is idempotent.
- a **sole-trader enrolment never outranks a company captured in the form**: selecting or
  typing one replaces it, and `TwoSoleTrader.setMode('business')` forgets it outright.
- a **country change** clears the display along with the fields its listener clears.
- values are written as **text, not markup**: a company name shaped like `<img onerror=...>`
  from the register, and one typed by the buyer, both render as characters. This is the only
  injection-relevant line in the module and it was unguarded until the review round asked.

It builds its DOM from the **shipped** `views/templates/hook/paymentinfo.tpl` via
`buildPaymentTile()`, which strips Smarty rather than copying the markup into the test. A
hand-written fixture would keep passing after someone renamed a class or deleted a slot in
the real template, which is the failure a tile test exists to catch — nothing else in the
suite reads that file. It also constructs a real `TwoCompanySummary` **instance**: the
document-level `input` listener that catches a hand-typed company name belongs to the
instance, so a suite that only loaded the class would find the manual-entry path dead and
still pass on the paths that call `render()` directly. That is not hypothetical — it is how
this suite first failed.

`company-search-dropdown.test.js` — TWO-25326 §1-§5 and §7, one test per bullet of the
cross-platform regression script, worded so a failure names the *requirement* rather than
the implementation. This is the suite that pins the anchored dropdown rework: that a click
or a keypress (but **not** plain focus) opens a panel anchored to the field, that the panel
carries its own query input and focus lands in it, that the company-name field is left
untouched until a result is picked, the 300ms debounce, the spinner in the query field, the
`type N more characters` hint being present from the moment the panel opens, the exact
`No matches found` wording, Escape closing and returning focus, `My company is not on the
list` as a real `<button>` outside the scroll container, and the org-number label sitting in
normal flow where it cannot collide with the field below it.

**Two jsdom limits are worked around explicitly, and both matter when reading a green run
here:**

- jsdom performs no layout, so `offsetWidth` is always `0` and jQuery's `:visible`/`:hidden`
  answer "hidden" for the entire document. Every visibility assertion goes through the
  harness's `shown()` helper, which walks computed `display` and the `hidden` attribute
  instead. A test written on `:visible` passes or fails for reasons unrelated to the code.
- jQuery UI's `_move` gates cursor-key navigation on `menu.element.is(":visible")`, which
  therefore can never be true here. Selections are driven through the widget's own
  `menu.focus()` + `menu.select()` rather than by dispatching Down/Enter, and tab order is
  asserted **structurally** — on document order among focusable elements, which is the
  property the browser derives tab order from, and which is the entire point of anchoring
  the panel inside the field wrapper.

Neither is a substitute for pressing the keys in a real browser, and TWO-25326 requires that
separately.

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
  `onCompanySelected()` call) lands in `company`, `companyid` and `dni` — and never in
  `vat_number`, because an organisation number is not a VAT number; a
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
- the submit hook restores `dni` from `companyid` without overwriting a value
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
- the in-field spinner **GIF** (TWO-25288). The spinner is the loader GIF, set as the company
  input's own `background-image` and painted purely by the loading class the module already
  puts on that input. Pinned on **both** render paths, because they set different classes and
  share nothing but the CSS contract, so covering one and assuming the other leaves half the
  surface untested with a green suite: the GIF resolves while a search runs and stops after,
  on the jQuery UI path, on the no-jQuery-UI fallback path (including after a failed search),
  and after an address-form re-render replaces the input.

  These are the only tests here that load a **stylesheet** (`installStylesheet()` in the
  harness). "The class is set" is a weaker claim than "the spinner appears", and the gap
  between them is not hypothetical: an unscoped `!important` rule further down the stylesheet
  used to out-rank the scoped one and paint a white box and its own gutter over the field,
  with the class set correctly throughout. One case pins that specifically, in the **loading**
  state — the removed rule was gated on the same class, so an idle field cannot see it.

  Nothing stubs the loading state. The harness replaces only `$.ajax` (the network) and the
  `prestashop` event bus; jQuery, the jQuery UI autocomplete widget and the module source are
  all real, so the class toggling that drives the spinner is unstubbed production code on both
  paths.

  One case reads the GIF off disk rather than trusting the stylesheet, because jsdom resolves
  a `url()` naming a missing file exactly as happily as one naming a real file — so every
  other assertion here passes with the asset deleted. It checks the file exists at the path
  the rule resolves to, that it is a GIF of the pinned 16x16, and that it has more than one
  frame: a still image would be a spinner that never spins, which no CSS assertion can tell
  apart from a working one. The frame count comes from `countGifFrames()`, which walks the
  GIF block structure — counting raw `0x2C` bytes across the file does not work, because that
  value also occurs inside the colour table and the compressed pixel data, so a single-frame
  file passes such a scan.

  **Two jsdom limits to know before adding assertions here.** The multi-value
  `background-position` form resolves to an empty string even when the rule is correct, so do
  not assert on it. And jsdom's own default stylesheet resolves every `input` to
  `background-color: white`, so an assertion that the field is *not* white can never fail —
  the white-box regression is pinned through `background-size` instead, which the removed
  rule's `background` shorthand resets to `auto`.

- the **manual-entry affordance** — `My company is not on the list`, and the
  `Search for company` link that leads back out of the manual entry it switches to.

  **Rewritten by TWO-25326.** It used to be the last ROW inside the dropdown's `<ul>`,
  carried through jQuery UI's item plumbing as a pseudo-result flagged `two_manual_entry`,
  and the assertions here were aimed at the *inversion* that made it work: every other
  non-company row carries `ui-state-disabled`/`aria-disabled` so the widget's menu skips
  it, while that one had to be reachable and selectable. None of that applies any more.
  It is now a real `<button>` and a sibling of the scroll container, so the properties
  under test are structural instead: that it is a `<button>` and not an element with a
  click handler bolted on, that it renders *outside* the scrollable results host, that it
  is the next tab stop after the query field by plain document order, that the cursor keys
  cannot reach it (it is not an item in the widget's menu at all), and that it is coloured
  distinctly from the inert rows above it. Those live in `company-search-dropdown.test.js`,
  below.

  **The key-event cases are the ones that matter most, and they must be driven
  through the real widget.** The widget's focus event fires *after* the menu has
  focused the row, and its return value gates only the write that mirrors a
  key-navigated item into the input — and it performs that write only for a
  **key-type** original event. So calling the `focus` option directly, as an
  earlier version of these tests did, cannot observe the defect at all: it passes
  whether the guard is there or not. The cases now trigger the widget's own menu
  focus event with a synthetic keydown original event, in both list shapes,
  because the normalizer behaves differently in each — alongside real companies
  the row keeps an empty value (an unguarded write **blanks** the buyer's term),
  and alongside a message row every value is rewritten from its label (an
  unguarded write puts the **affordance text** into the field).

  Focus restoration is asserted on both paths, on activation and on the way back,
  via `document.activeElement`. This is the one behaviour whose regression is
  invisible to a sighted mouse user and total for a keyboard one, because
  activating the row removes the focused element from the list.

  Forgetting the selected company is asserted too, and on all three of the places a
  selection writes the organisation number — because two of them were missing and
  the defect they left was invisible. The hidden number and its company-name marker
  are dropped; the session company is cleared through its own endpoint action,
  asserted to be a POST carrying the token and asserted *not* to be the save
  action, which rejects an empty company id and would therefore clear nothing; and
  the address step's `dni` is dropped, which the server reads off the saved address
  independently of the session company.

  Two things about those cases are deliberate. The selection is completed **through
  jQuery UI's own menu**, not by setting the hidden field by hand: a hand-set
  stand-in reaches one of the three fields, leaves the other two empty, and every
  assertion about what a clear does to them then passes vacuously — which is
  exactly how the disowned number survived unnoticed. And the clear is asserted
  **through a form submit** as well as directly, because the pre-submit sync adopts
  a `dni` with no organisation number beside it *as* the organisation number, so a
  clear that leaves one behind silently undoes itself one step later. The
  complementary case is asserted beside it: a `dni` the buyer typed themselves is
  still adopted at submit, which is what rules out a blanket clear.

  A missing endpoint is asserted to be tolerated with the local half still
  happening. What makes that tolerable is asserted in PHP, not here — see
  `tests/SessionCompanyClearSpec.php`, which drives both the clear action and the
  address-save backstop that holds when the browser's fire-and-forget request never
  arrives.

  On the fallback path the row has no widget to lean on, so it carries its own
  `role="button"`, `tabindex="0"` and Enter/Space handling, and each of those is asserted
  directly — including that Space is `preventDefault`ed (its default action is to scroll)
  and that an unrelated key does nothing. Two fallback-only cases matter more than they
  look: every one of that path's four renderers wipes the list's `innerHTML`, so the footer
  is asserted separately in the loading, results, zero-result and failure states; and
  moving focus onto the row blurs the input, whose blur closes the list 150ms later, so one
  case blurs the input, focuses the row, advances the timers and then activates it by
  keyboard. Without the cancel that case pins, the affordance would be pointer-only in
  practice however good its ARIA looked. A third closes the other half of that:
  the row re-arms the close on its own blur, because the input is otherwise the
  only node that closes this list and the row is now the first tab stop after the
  company field whenever the dropdown is open — so tabbing onward would have left
  the list painted over the address form indefinitely.

  **The PHP half of this element cannot be covered here at all.** Two seams are
  invisible to this suite because it stubs both sides of them: the dictionary keys
  (this suite supplies its own `i18n` object, so a PHP key renamed to a typo leaves
  every case green while the shipped row is permanently untranslated) and the
  endpoint action name used to clear the session company (the transport is stubbed,
  so a name that agrees on neither side fails silently and the disowned company is
  still credit-checked). Both name agreements are pinned in
  `tests/CompanySearchCountrySourcingSpec.php`, which is where seam assertions for
  this feature live — spelling only. What the clear action DOES, and the address-save
  backstop that holds when the browser's request never arrives, are driven for real
  in `tests/SessionCompanyClearSpec.php`. That split is deliberate: a source grep
  cannot see an early `return` above the work it greps for, and did not.

`sole-trader-mint-country.test.js` — TWO-40's browser half of "a sole trader can start
enrolment from the address-editor page":

- the mint request carries the country `billingCountry()` resolves, urlencoded, and it is
  asserted as an *equality* against that resolver rather than a second hardcoded ISO code —
  the token gate and the chip's visibility gate have to agree by construction, so a mint that
  grew its own country source has to break here.
- driven through the real `fetchTokens()`, with the country reachable only from the DOM select,
  so a regression to a config-time value — or to no country at all — fails here rather than
  passing on a hardcoded fixture.

The server half is in `tests/SoleTraderTokenPreconditionSpec.php`, which drives the mint
action through the controller's own switch: the tier ordering, the shape check on the posted
country, and that a posted country is still gated by the registry rather than trusted.

`sole-trader-chip-visibility.test.js` — why the "Sole trader" chip did not render for GB
at all (Doug, 2026-08-19). The only suite that runs the REAL `TwoSoleTrader` beside the
real search control: all three defects live in the seam between the module that resolves
the availability answer and the one that draws the chip, so a stub on either side hides
them. Covers the answer landing while the panel is already open, every declined-request
body shape (`success: false` and friends, all HTTP 200, so none reaches the transport
catch), and that a negative answer is never persisted across page loads.

### The sole-trader in-flight spinner: three suites, and which one is load-bearing

The spinner sits over the company-NAME field, and "in flight" runs until the popup has
closed AND the buyer lookup has resolved AND the name/number have been written. Those
last two are what make the coverage awkward to place, so it is split deliberately:

- `sole-trader-flight-settled.test.js` owns the DURATION, on the `TwoSoleTrader` side,
  and is the load-bearing one. Note especially the test that closes the popup while the
  write-back is still held open by hand: it asserts the settle event has NOT fired at
  that point, which is the only assertion in the suite that fails against a
  popup-close-only signal. Every other test here passes either way, so removing that
  one silently removes the coverage for this rule.
- `sole-trader-select-different.test.js` owns the shared flow: the spinner appearing for
  the standalone-button entry point (it previously showed none), the shared re-entrancy
  guard now refusing a chip click during a button-launched flight, and the
  close-the-dropdown-only-if-open split between the two.
- `sole-trader-chip-adopted-state.test.js` owns the LOCATION's consequence — the query
  row hiding immediately on the chip click and staying hidden for the flight, driven
  with no close/reopen in between, since a suite that reopens the panel cannot tell an
  open-handler-only implementation from a correct one.

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
