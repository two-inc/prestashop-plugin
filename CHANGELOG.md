# Changelog

All notable changes to the Two Payment module for PrestaShop will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **Company search is now a real anchored dropdown with its own query field, not an in-field autocomplete** (TWO-25326, umbrella TWO-24739)
  - PrestaShop was the last of the four plugins where `input[name='company']` WAS the search box. Everything typed went into the field the buyer's confirmed company name lives in, so the module had to defend that value with a click-to-reveal chip painted over the input (TWO-25288 element 2). TWO-25326 §1 requires the split the chip was standing in for: a visually distinct popup, anchored to the field, carrying its own query input, with the company-name field left untouched until a result is picked
  - The panel is built by `TwoCompanySearch.buildDropdown()` as a child of `.two-company-field-wrap`, in the order company-name input -> query field -> results host -> `My company is not on the list`. **That placement is the whole keyboard contract.** Because the panel is a DOM child of the field's own wrapper, the browser's native tab order already satisfies §1's "the query field is the next tab stop after the company-name field", §2's "is the next tab stop after the query field" and §4's "Tab from the not-on-the-list control moves to the next control in the tab order" — with no key handling at all. select2/selectWoo append their dropdown to the end of `<body>`, which is why the sibling plugins had to re-implement Tab by hand and why theirs lands on `<body>`. The panel is `display: none` while closed, so §4's "no keyboard trap" holds by construction rather than by testing
  - jQuery UI autocomplete is still the search engine, but bound to the **query field** rather than the company field, with `appendTo` pointing at the panel's results host so the widget's `<ul>` cannot escape the control. The widget's own `tabindex="0"` on that `<ul>` is overridden to `-1`: left alone it makes the scrollable results list a tab stop of its own, sitting between the query field and the button
  - `My company is not on the list` is a real `<button>` and a **sibling** of the scroll container, not a pseudo-row inside the results `<ul>`. It is therefore visible without scrolling past up to 50 results, reachable by Tab, and unreachable by the cursor keys — which only ever move within the widget's list. Coloured link-blue (`#3043d1`), matching the sibling plugins, so it is distinguishable from the inert result rows. It is shown whenever the search UI is open and no company is captured yet, explicitly **not** gated on the 3-character search threshold: a buyer must be able to reach manual entry without typing a doomed query first
  - A completed search that matches nothing now says exactly **"No matches found"**. PrestaShop previously rendered nothing at all, which is indistinguishable from a search that never ran. New translatable key `company_search_no_matches`, added to all four catalogues; the wording is checked verbatim by the cross-platform test script, so "No results found" does not satisfy it
  - The "type N more characters" hint is now shown for an **empty** query too, so it is on screen the moment the panel opens rather than only after the first keystroke. `buildFocusHintItem()` is removed — one hint, one wording, one state
  - The spinner GIF moved into the query field, at its right-hand end, per §1
  - The click-to-reveal chip is **removed**. Its stated reason for existing was that this field had no separate search box; it now has one, and the chip's behaviour — blanking the company-name field on click — is the opposite of what §1 requires
  - The non-jQuery-UI fallback path is now an **engine only**: it renders into the same panel the live path uses, instead of building a second, divergent dropdown of its own. Every §1/§2 defect on this ticket previously had to be fixed twice, and several were only ever fixed once

- **Advanced-settings label is sentence case: `Enable company search in address entry`** (TWO-25326). Every other label on that form is sentence case (`Auto-fill the address from the selected company`, `Automatically fulfill orders with Two`); this one was Title Case for word-for-word parity with `woocommerce-plugin`/`magento-plugin`, and house style on the page wins. Only the capitalisation differs from those plugins now. Re-keyed in the `no`/`nl`/`sv` catalogues; the translated wording is unchanged

- **The captured company renders as a single `<name> (<number>)` label** (TWO-25326 §7), between the term chips and the optional fields, replacing the two-row `Company` / `Company number` label-and-value block. The number and its parentheses are hidden as a unit when there is no number, so a manual-entry buyer sees the name alone rather than `Example Ltd ()`. The two now-unused `paymentinfo_*` label strings are dropped from the four translation catalogues

### Fixed
- **The order-intent check is made for the company the buyer just picked, not the one before it** (TWO-25326). Selecting a company fired a correct check; searching again and selecting a *different* one fired the check for the *first* company. The monotonic request-sequence gate added earlier is not the fix and was never at the right layer — it can only choose between answers already in flight, and the request that fired for the previous company was, at that layer, entirely current. The company came from the wrong place: with the search control in the payment tile, the intent check deliberately reads nothing from the address form and instead asks the server for the session company — over a request issued in the *same tick* as the fire-and-forget request that writes that session company. A value written by a response the browser has not received yet is not in the request it has already sent, so the read answered with the selection *before* this one: nothing at all on the first search (which the server's own fallbacks quietly repaired, so the first cycle looked right) and the previous company on every search after it. The selection the browser already holds in memory is now the authoritative source for the payload, and needs no round trip to be current. It remains subject to every existing invalidation — a country change or an address switch still discards a captured company rather than being bypassed by the shortcut
- **The `Registered business` / `Sole trader` chips stop flickering** (TWO-25326). Two independent causes, both in the sole-trader module and neither touched by the earlier tile-level mount/unmount fix. First, "already rendered" was recorded as a *country* rather than as a rendered container: PrestaShop replaces the payment fragment — and with it the whole toggle container — repeatedly while the checkout step settles, and the replacement arrives with no chips in it, so the module read the country as unchanged and returned early, leaving the chips missing from a container it believed it had already filled. Second, there was no in-flight guard on the availability request, and the module watches the whole document for changes while *rendering into* it — so every mutation started another request. Beyond the request storm, that made the toggle's visibility a race between those responses: the endpoint is fail-soft to "not available", so one failure among a dozen duplicates hid the chips while its siblings showed them. Requests are now one-per-country, the observer is debounced, and the settled-check is keyed on the live container node. A failed request is no longer cached either — one dropped request used to hide the toggle for the rest of the page's life
  - **Neither of those was the flicker Doug was seeing, and the toggle is now rendered server-side** (TWO-25326, round 3). Both causes above are real and stay fixed, but both are about not redoing work *after* the availability answer arrives — and the flicker lives entirely in the window *before* it arrives. Measured on the staging shop: the toggle is `display: none` with zero chips at first paint and only becomes visible with two chips ~280ms later, on **every** load, because the chips were built only in the browser and only after a round trip. On a page the buyer has just arrived at that is invisible; the payment step is not that page, because selecting any payment option syncs the buyer-surcharge cart line and a cart change on the payment step is a full checkout-page reload in PrestaShop core. So the buyer sees chips, then a document with no chips, then chips again. The answer now comes with the markup: `paymentinfo.tpl` renders the toggle's visibility and its two chips from the same registry answer the module's own endpoint returns, and the browser module *adopts* that as its settled state and issues no request at all. It still re-resolves normally when the buyer changes country, and markup carrying no answer (an older cached template) still falls back to the request, so the change cannot take the toggle away from a shop where the handover does not apply
  - A resolved sole-trader instance now detaches **both** of its page-level subscriptions rather than only its DOM observer. The country-change listener was an anonymous closure with no way to remove it, so an instance that had finished its flow and deliberately stopped maintaining the toggle stayed a live second writer to it for the rest of the page's life

- **The Two tile no longer flashes back for a moment after the buyer selects a different payment method** (TWO-25326). Doug: "our tile disappears for a second, then reappears briefly and disappears again." All three beats are real and were measured. Deselecting Two removes the surcharge cart line, and a cart change on the payment step makes PrestaShop core reload the whole checkout page — the tile leaves with the old document. The reloaded page then renders *every* payment option's additional-information block expanded, because a reload wipes radio state and core only collapses the unselected ones from its DOM-ready handler: the Two tile was ~497px tall at first paint. ~34ms later the module restores the option the buyer had actually clicked and it collapses to nothing. Nothing running at DOM ready can prevent that, because by then it has been painted — so a small guard now loads in the `<head>`, ahead of the payment markup, and keeps the tile out of that first paint until the selection has been restored. It is deliberately narrow: only on a load caused by this module's own surcharge refresh, only when the option being restored is **not** Two's (when it is, the tile is painted expanded and stays that way, so there is nothing to suppress and hiding it would invent a flash), and only if it genuinely got in ahead of the markup — so it degrades to doing nothing rather than to a new defect. The suppression is lifted as soon as the selection is restored, on every path out of that code, and by the guard's own failsafe timer if that code never runs at all
- **The company-search control resizes with the window** (TWO-25326). The wrapper's width is pinned in pixels, measured from the input — and the input is `width: 100%` of that wrapper, so after the first pin the measurement read the pinned value straight back. The resize listener ran on every viewport change, re-measured the number it had written last time, and re-pinned it: a latch, not a missing listener, which is why the optional fields (pure CSS, no JS width) reflowed and this control did not. The pin is now released before measuring, so the input measures against the real layout again
- **`TWO:`-prefixed company numbers are never displayed** (TWO-25326, cross-platform requirement). The sole-trader enrolment flow mints a synthetic organisation number and stores it in the same field as a real register number; it is an internal identifier and must not be shown to the buyer. Suppressed at all three display sites — the label under the company-name field, the search-results rows, and the order-intent sentence — through one shared rule rather than three prefix tests, with the surrounding brackets dropped along with the number (`Example Ltd`, never `Example Ltd ()`). Only the rendering changes: the value is still what gets captured, persisted and credit-checked
- **An API key that cannot be verified is now diagnosable, and no longer leaves Two on offer at checkout** (TWO-25326). Every non-200 from the verification endpoint collapsed into one outcome: the merchant was told "API key verification failed. Please check your API key." whether the key had actually been rejected (401/403), Two had answered with a 5xx, or the shop could not reach Two at all — advice that is correct for exactly one of those three, and actively misleading for the other two. The outcome is now categorised (`invalid_key` / `service_error` / `unreachable` / `error` / `not_configured`) and the config page states the category and the HTTP status — never the response body, which stays in the log where it belongs
  - The verdict is also a **checkout gate** now, which it could not be before: verification only ever ran on the settings-save path, so a shop whose key stopped verifying kept offering Two and kept rendering the company-search control until someone happened to re-save the settings. `hookPaymentOptions()` withholds the payment option for **any** non-ok category, not just a rejected key — a buyer must not be able to pick a payment method whose integration is not answering — and the same verdict reaches the browser, so the address-step company search stands down: nothing consumes a captured company once Two is withheld, and a “search and verify your company” journey that leads nowhere is worse than a plain field (the search itself is called unauthenticated and would still function — it is the destination that is gone). The search-mode placeholder the address-form override applies server-side is taken back off the field in that state, so it stops instructing the buyer to do something that cannot happen
  - Withholding is **logged** with its category and status (once per request, not once per payment-options evaluation): a payment method that silently vanishes is the same "nobody could tell why" failure this change exists to remove
  - A buyer already sitting on the payment step when the verdict changes can still POST the payment form; that submission is now refused by the payment controller in the same shape as every other unavailability there, rather than proceeding and failing opaquely at order creation
  - The config page's green "API key verified" panel and the health checklist's "API key" row now read the same live verdict, instead of a flag written only at save time — they could otherwise report "Verified" directly above the notice saying Two is hidden. `PS_TWO_API_KEY_VERIFIED` remains as a save-time record the module itself no longer reads
  - The search-mode placeholder is also withheld server-side by the address-form override, not only stripped in the browser: the browser can only recognise its own catalogue's wording, so a shop with a back-office translation of the core string kept the hint. The override fails open if it cannot reach the module instance
  - The verdict is cached in Configuration behind a TTL clock (the shape the FX table and merchant record already use) so a checkout render costs a config read rather than an HTTP call, with a tight timeout on the cold-cache call because that one lands inline in a shopper's page render. The slot is claimed before that call so concurrent renders do not each fire their own verification; the claim carries the previous verdict for the same key and environment, so a routine re-verification never blinks Two off a healthy shop, and carries no verdict at all when there is none to carry, so a shop whose key has never verified is never briefly treated as verified. An abandoned claim expires in seconds rather than a full TTL, and the slot is keyed to the API key AND the environment, so neither a key change nor an environment change can inherit the other's verdict.
  - The server-rendered search placeholder and the browser-side control are two halves of one affordance, and are now driven by the *same* predicate rather than by two similar ones: both stand down on any KNOWN failure, and both leave the field alone while the verdict is merely unknown (a cold cache is not evidence of a broken shop, and an address form — which also renders on my-account pages — may not go to the network to find out). Previously the browser half stood down on an unknown verdict too, so on a shop with a back-office translation of the core placeholder string — the exact case the server-side half exists for — the hint could survive on a field with no search behind it
  - The payment POST reads the verdict cache-only and refuses only on the DEFINITIVE categories (`invalid_key`, `not_configured`), because refusing a submitted order over one transient blip costs the buyer the order. It reads past the TTL as well — the buyer this gate exists for reached the payment step minutes ago, so a fresh verdict is exactly what it cannot have, and "Two rejected this key, when last asked" is better information than nothing. Transient categories never gain that power however long they sit in the slot. A failing verdict expires after a minute and a healthy one after five, so recovery reaches checkout quickly without re-verifying a working shop constantly; a verdict is bound to the key it was reached for, so pasting a replacement key never inherits the old key's failure; and the settings-save path publishes its own fresh verdict, so a just-fixed key takes effect immediately instead of waiting out the TTL
- **Enabling company search in address entry now also switches `Auto-fill the address from the selected company` ON, not just enabled** (TWO-25326). The admin JS already greyed the auto-fill switch back in and left it enabled once search moved back to the address area, but left its checked state untouched — so it could sit re-enabled yet still showing (and posting) `No` from when it was forced off, reading as "on" to the merchant at a glance while auto-fill stayed off after save. The auto-check only fires on the admin's own toggle, never on the page's initial render, which still has to respect whatever position is actually stored
- **A slower intent check for a previously selected company could overwrite a newer one's result** (TWO-25326). Selecting company A fired a correct intent check; selecting a different company B before A's request returned could let A's response land AFTER B's and silently replace B's already-rendered result and captured company with A's stale data — `TwoOrderIntent.reset()` (run on every fresh selection) forced `isProcessing` back to `false` so the new selection was never blocked, but nothing stopped the OLD request's promise chain from still writing its result. Every `checkOrderIntent()` call now carries a monotonic sequence number, mirroring the equivalent guard `TwoCompanySearch.js` already uses for its own search requests; a request whose sequence has been superseded by a newer selection is dropped rather than published, and never even reaches the real Two API call
- **The payment tile could render, disappear and re-render several times in a row on payment-step changes** (TWO-25326). `handleDynamicContentChange()` — run by the module's own `MutationObserver` every time PrestaShop replaces the `.payment-options` fragment while the checkout step settles — forced its `_paymentListenersAttached` guard back to `false` and re-bound the change/click/submit listeners on every firing, despite them being delegated to `document` and therefore never needing re-binding across a DOM replacement in the first place. Each firing left a further, permanent set of duplicate listeners with no way to remove them again, so a single click or radio change could invoke the payment-selection handler - and the cart-line resync/full payment-step reload it can trigger - several times concurrently. `handleDynamicContentChange()` no longer touches the listener guard at all
- **The payment-tile company search actually searches** (TWO-25326 §7.1 follow-up). With `Enable company search in address entry` set to `No` the control renders in the payment tile — and typing into it fired no request at all. `TwoCompanySearch.getCurrentCountry()` could only read `select[name='id_country']`, and PrestaShop renders the address *form* (and therefore that select) only while the buyer is editing an address: on the payment step the step shows an address *selector* instead. So the country never resolved, `searchCompanies()` took its `countryUnresolved` branch on every keystroke, and the dropdown told the buyer to pick a country "above" — where there is no country control. The module now injects `billing_country`, the ISO code of the cart's own invoice address resolved server-side, and the browser reads it as a **last** resort behind the three existing strategies, so a buyer mid-edit on the address step still searches the register they have selected rather than the one their saved address carries. A malformed value is treated as absent: it still refuses to search rather than guessing a register
- **The tile's "go back to your billing address and search for your company" prompt is no longer shown when the search is in the tile** (TWO-25326 §7.1 follow-up). The whole point of the message is to send the buyer somewhere else, which is correct while the control lives in the address area and simply wrong once the switch has moved it into the tile the message itself is rendered in. There is nothing to reword it to — the instruction is the part that no longer applies — so the block is not rendered at all in that mode, and a prompt an earlier check left on screen is taken down. Genuine errors are untouched: only the company-missing prompt is suppressed, and only in tile mode
- **The tile's company-search control is no longer wider than every other field in the tile** (TWO-25326 §7.1 follow-up). `.two-optional-fields` carries a 16px horizontal inset and `.two-tile-company-search` carried none, so with both inputs at `width: 100%` of their own container the search rendered 32px wider, flush with the tile's edges while the optional fields sat inset
- **`Auto-fill the address from the selected company` can no longer be enabled while company search is not in the address area** (TWO-25326 §7.1 follow-up). It governs what a company selection writes into the checkout *address* step, so it means nothing once the search has moved into the payment tile — but it stayed independently settable, so a merchant could tick a box the module then ignored. It is now greyed out and forced to `No` in the admin form, refused on save however it was posted, and reported as off to the checkout even on an install that has not re-saved its advanced settings since the search relocated. Mirrors `woocommerce-plugin`'s admin JS for the same pair of settings, plus a server-side half Woo does not have
- **The company number label no longer collides with the field below it** (TWO-25326 §5). It was absolutely positioned at `top: 100%`, and the `padding-bottom` on the wrapper meant to reserve room for it could not work: for an absolutely positioned child, `top: 100%` resolves against the containing block's *padding* box, so the padding pushed the label further down instead of clearing space above it, and it landed on the VAT-number field. The label is now an ordinary in-flow block — it cannot overlap anything by construction — right-aligned with `text-align: end`, and `display: none` while empty so an unselected field gains no height at all
- **The company-name field no longer carries 32px of dead padding** (TWO-25326 §7). `.two-company-search-input` reserved room for an in-field spinner unconditionally, to stop the field's text reflowing as the spinner appeared. The spinner now lives in the query field, so that padding was 32px making the company-name field visibly unlike every other input on the address form
- **No unwanted gap between `Search for company` and the next form field** (TWO-25326 §7) — the wrapper's unconditional bottom padding, removed above, was stacking underneath the link
- **`Search for company` is right-aligned under the field** (TWO-25326 §3), via `width: fit-content` plus an auto inline-start margin, so the clickable area stays the text rather than the whole row
- **`clearSelectedCompany()` cleared the organisation number but left the visible label still showing it** — the name/number pairing every other clear path in the module maintains
- **A jQuery UI widget was leaked on every address-form re-render.** `setupAutocomplete()`'s node-swap branch called `autocomplete('destroy')` on the outgoing *company-name* field, which is dead code now the widget lives on the panel's query field, and never released the outgoing *query* field's widget. The element goes with the detached subtree but the handlers `_create` binds on `document` do not, so PrestaShop firing `updatedAddressForm` for something as ordinary as a country change leaked a set per event. `removeDropdown()` now destroys the widget while its element is still attached
- **Dragging the results scrollbar closed the dropdown.** Focus moves to `<body>` in Chrome during a scrollbar drag, so the `focusout` close fired mid-scroll — with up to 50 results, the ordinary way to browse them. Guarded by a pointer-down flag on the panel, re-evaluated on `mouseup`
- **The no-jQuery-UI fallback had no keyboard selection at all.** Result rows carried a `mousedown` handler and nothing else, so a keyboard buyer could open the panel, type a query, see results and have no way to choose one — the live path gets this from the widget. The fallback now has cursor-key navigation and Enter, sharing a single activation path with the pointer so the two cannot drift
- **The fallback left the panel blank for 300ms on open**, because the too-short state was rendered inside the debounce. There is no request to debounce in that branch; it now renders synchronously, so §1's hint is on screen as the control opens on both paths
- **jQuery UI's `<ul>` painted a second bordered box inside the panel** — the rule flattening it into normal flow set `border`/`box-shadow` without `!important`, and the generic `.ui-autocomplete` rule sets them with it
- **Enter in the query field could submit the address form.** jQuery UI only suppresses Enter when it has an active menu item, so on the ordinary too-short and "No matches found" states the key reached PrestaShop's `<form>` and triggered implicit submission — type a company name, press Enter before the results land, and the address step submits. Enter is now suppressed whenever the panel is open, on both render paths
- **ArrowUp from the unselected state skipped a row** on the fallback path, landing on the second-to-last rather than the last
- **The panel was anchored to the wrapper's full height**, which grows by the org-number label once a company is selected, so reopening it dropped the panel further from the field than the 8px §1 asks for. Anchored to the input's own height instead

### Added
- **Norwegian, Dutch and Swedish translation catalogues** (TWO-24760)
  - `translations/no.php`, `translations/nl.php` and `translations/sv.php`, each carrying all 446 distinct strings currently reachable from a `->l()` call or an `{l s=... mod='twopayment'}` tag. The module previously shipped English plus Spanish only; this brings the locale set level with the sibling plugins
  - **Norwegian is `no.php`, not `nb.php`.** `Translate::getModuleTranslation()` loads `translations/<iso_code>.php` and PrestaShop's `iso_code` for Norwegian is `no` — its language pack ships a `no` entry and no `nb` one. An `nb.php` would have been read by nothing, with no error and no log line to say so
  - **Keys are the ones the module looks up at runtime, which is not what the back office would write.** Every `->l()` call here reaches `Module::l()` with no `$specific`, so the key's source segment is the module name for all PHP strings — including the 45 defined in the five front controllers, which are called as `$this->module->l(...)`. Only template strings carry a per-template segment, via `smartyTranslate()`. The back office translation screen instead derives that segment from the filename in both cases, so **these files must not be regenerated from it**; see the i18n section of `AGENTS.md`
  - Rows whose translation equals the English source are kept deliberately — the lookup does no identity filtering, it simply misses the key and falls back. Values avoid literal backslashes and never render as `0` or empty, because the lookup guards with `!empty()` and applies `stripslashes()` to whatever it finds
  - Wording shared with the Magento plugin's catalogues is reused verbatim so common concepts read the same across plugins; that covers 34-36 strings per locale. The remainder is machine-generated pending human review. Dutch uses the informal *je*/*jouw* register, matching the sibling plugins
  - **New `tests/TranslationCatalogueSpec.php`**, because nothing pinned any of this before: it re-derives every runtime lookup key from source the same way the module does and asserts each gated catalogue (`no`, `nl`, `sv`) matches exactly, including sprintf-token parity against the English source. `translations/es.php` is deliberately not gated yet; see the spec's class comment
  - **No migration and no new upgrade script**: three new translation catalogues plus the new spec file, no other production behaviour change. No configuration key, no database column, no request shape change
  - Known, pre-existing and **not** addressed here: `translations/es.php` is in worse shape than its row count suggests — only 309 of its 403 rows can ever be hit, 49 of the dead ones are keyed to controller filenames (so those Spanish strings have never rendered), and it is missing 137 strings that exist in the source today
- **The payment tile now shows the captured company back to the buyer, read-only** (TWO-25288, umbrella TWO-24739)
  - The company is identified in the **address** step, two steps earlier: the name in PrestaShop's own `company` input, the organisation number in a hidden `companyid` input beside it. By the time the buyer reaches the payment tile neither is on screen, and the number was **never** on screen anywhere — so the tile offered no way at all to check which company was about to be credit-checked and invoiced. It now shows both, as a `Company` / `Company number` label-and-value pair
  - **Read-only, and structurally so rather than by attribute.** The values are `<span>`s — matching the pattern the admin order tab and the sole-trader status line already use, not a `readonly` input — so there is nothing to type into, nothing to clear, and nothing with a `name` to submit. The block sits in the payment tile, which PrestaShop emits as a **sibling** of the module's payment form and never a child of it, so no part of it is inside any form
  - **The hidden `companyid` input keeps its job unchanged.** It remains the sole carrier of the organisation number into the address form's submission; the new display reads the DOM and never writes to it. Pinned by test, because a display that quietly became a second writer of that value is the failure mode that matters here
  - **Three capture modes, two slots.** Search and sole trader show name and number both. Manual entry shows the name the buyer typed and leaves the number slot **blank rather than absent** — a slot that disappears reads as a rendering fault, whereas a blank one reads as "this buyer has not given one". The row keeps its height for the same reason
  - **A number is only ever shown beside the name it was confirmed against.** The company search tags the hidden input with that name, and a number whose tag no longer matches what is in the field belongs to a company the buyer has moved off — retyping over a selection, or choosing `My company is not on the list`. In that state the number slot blanks. Asserting a pairing that does not exist is the one thing a display like this must not do
  - **Sole trader is pushed, not read.** That flow writes neither DOM field — it persists the enrolled pair server-side through `saveCompany` — so there is nothing on the page to read, and the pair is held in module-static state that outlives the tile. A sole trader with no company name is data rather than an error, and shows their number alone
  - **It re-paints after PrestaShop replaces the payment step.** Listeners are delegated off `document` and the values are re-read on every render, so nothing is cached from a node that is about to be replaced; the re-render is deferred a macrotask so it lands after the swap rather than during it. The company search calls in directly at each point where it changes the pair — a selection, a clear, a country change, and the details-fetch refinement — because those writes go through `.val()` / `.attr()` and fire no event that could be observed
  - **A sole-trader enrolment never outranks a company captured in the form.** The enrolled pair is consulted only when the address form has nothing at all to say, and switching the toggle back to `Registered business` forgets it outright. The read order is the backstop for any other path back to business mode that does not: without it a stale enrolment named one company in the tile while the order carried another, for the remaining life of the page
  - **Known, accepted residual: a full checkout-page reload leaves the number slot blank in search mode.** A surcharge-line sync is *not* a partial re-render — core fully reloads the checkout page for it, because the payment step carries the `js-cart-payment-step-refresh` marker. After that reload the server re-renders the address form with the saved company **name**, but the hidden organisation-number input is recreated empty (it is not one of PrestaShop's own address fields), so the tile shows a name with a blank number — visually indistinguishable from manual entry — while the session still holds the number that will actually be credit-checked. The display is incomplete in that state, never *wrong*: it will not show a number paired with the wrong name, which is the invariant that matters here. Closing it needs the tile seeded from the server-side session company rather than from page-local state, which is a server-side change and deliberately not in this PR
  - **Known, accepted residual: the GB details-fetch retag.** For registries that return the organisation number only from the company-details endpoint, the existing capture code re-tags the hidden input using whatever is in the company field *at the moment that request resolves*, rather than the name confirmed at selection. Typing over the field while the request is in flight therefore poisons the tag, and the tag comparison this display relies on cannot detect it. Pre-existing behaviour of the capture path, not introduced here, and fixing it changes which company gets credit-checked — so it is left for its own ticket rather than widened into this one
  - **The block stays hidden until there is something to show**, so a buyer who has not named a company sees no empty labels
  - **Spanish added for both new strings**, in the informal *tú* register the rest of that catalogue uses
  - **New Jest suite** (`tests/js/company-summary.test.js`) covering all three modes, non-editability, and the hidden input's submission behaviour. It renders the **shipped** `paymentinfo.tpl` rather than a fixture copy, so renaming a class or dropping a slot in the real template fails the suite
  - **No migration and no new upgrade script**: one template block, one new browser-JS module, CSS, one translation catalogue and a `registerJavascript` line. Nothing under `override/`, no configuration key, no database column, and no request shape changes
- **The company-search dropdown now offers a way out of itself** (TWO-25288, umbrella TWO-24739)
  - `My company is not on the list` is now the **last row inside** the dropdown, at or above the search threshold. Choosing it switches the field to plain manual entry — no dropdown, no suggestions, no hint rows — and a `Search for company` button appears below the field to switch back. Previously the buyer whose company the register does not return had nothing to click at all: the dropdown either showed other companies or showed nothing, and neither says "type it yourself"
  - **It appears with the first rendered result set at or above the threshold, and persists through every state after that** — real results, zero results, a failed search, and "select your country". Zero results is the state it matters most in, so the dropdown now opens for that row alone rather than closing and saying nothing. Below the threshold it is deliberately absent: nothing has been searched for yet, so "not on the list" is a claim the buyer is in no position to make
  - **Keyboard reachability is part of this change, not a follow-up**, and it is the inverse of every other non-company row in this dropdown. Those rows carry `ui-state-disabled` and `aria-disabled` precisely so that keyboard navigation *skips* them; this one is arrow-key reachable, announced as selectable, and activates from the keyboard. It therefore has its own flag rather than reusing theirs — sharing it would have given the row the exact opposite of the treatment it needs
  - **Both render paths, by different mechanisms.** On the jQuery UI path the row enters as a pseudo-result through the `source` callback and is special-cased in `select`, `focus` and `_renderItem`: `select` runs the action and returns `false` (which is the only thing keeping the row's label out of the company field — jQuery UI rewrites an item's empty `value` to its label before any handler sees it), and `focus` deliberately does *not* refuse it. On the hand-rolled fallback list used by themes that ship no jQuery UI there is no keyboard model at all to extend — the rows there are plain divs with `mousedown` handlers, no roles and no arrow keys — so rather than retrofit a listbox, the footer carries `role="button"`, `tabindex="0"`, its text as its accessible name, and explicit Enter/Space handling
  - **Two fallback-path details that would each have made it pointer-only.** Every one of that path's renderers wipes the list, so the footer is re-appended by each of them instead of once. And moving focus onto the row blurs the input, whose blur closed the list 150ms later — before any key could land — so the row now cancels that pending close when it takes focus
  - **The reverse control is a real `<button type="button">`**, not a `<div>` or an `href`-less `<a>`: focusable, Enter/Space-activated and announced as a button with nothing added by hand. `type="button"` because it sits inside PrestaShop's own address form, where a default-type button submits it. Leaving manual entry re-runs the search for whatever is already in the field, so the buyer does not retype
  - **Manual entry survives an address-form re-render.** PrestaShop replaces that form for something as ordinary as a country change, which takes the reverse button's node with it; the link is put back rather than leaving the buyer in manual mode with no way out
  - **The raw results are what gets cached, never the list with the footer appended**, or the next cache hit would show two of them
  - **Choosing it forgets the selected company, and that is load-bearing rather than tidying.** "My company is not on the list" is a statement that the selected company is *wrong*, and the organisation number is what decides who gets credit-checked and invoiced — so a number that outlives its selection means a genuine buyer receiving an invoice for an order they did not place. Entering manual entry therefore clears **every** place a selection put that number, in three passes:
    - the hidden organisation-number field and its company-name marker;
    - the **session company**, through its own endpoint action. Its own action rather than a save carrying empty values, because the save rejects an empty company id outright and answering "missing company data" would have made the clear a silent no-op;
    - the address step's **identification-number and VAT-number fields**, which a selection also writes and which the server reads off the saved address on a path of its own, independently of the session company. Leaving these two behind did not merely leak a stale value — the pre-submit sync adopts an identification number with no organisation number beside it *as* the organisation number, so the disowned number was re-adopted at submit and re-tagged with the name the buyer had just typed. The clear removes only what the lookup itself **marked as its own write and the buyer has not since changed** — never a buyer-typed value, because a buyer-typed identification number is legitimate and is the only route by which a manual-entry buyer's own number reaches the flow. **Known, accepted residual:** a saved address rendered by the server on page load (e.g. from a previous checkout) carries no such marker — a server-rendered value and buyer input are indistinguishable by construction — so the clear is a no-op against it, and a pre-submit sync can re-adopt that unmarked, pre-existing `dni` as the organisation number. The marker approach cannot close this without guessing which unmarked values are safe to erase, and guessing wrong (erasing a buyer's real answer) is worse than leaving this open; refusing to guess is the deliberate call here, not a gap to chase
  - **The clear is backstopped server-side, so it does not depend on the browser request arriving.** That request is fire-and-forget; a dropped one, or one still in flight when the address saves, would otherwise have yielded a wrong order rather than a rejected one, silently — the resolver returns the session company first, with no comparison against the address. Saving an address whose company name differs from the session company's, with no organisation number in the form, now drops that number and its country marker: which is what disowning a selection looks like by the time it reaches the server. A capitalisation or spacing tidy-up is not a change, and an ordinary address edit that simply omits the hidden field is not either, so a good selection survives both. **Retyping over a selection is NOT closed by this backstop, in general.** Retyping the company name DOES clear the hidden `companyid` field client-side — a separate input/change listener (`clearStaleOrganizationSelection()`) blanks it as soon as the typed name diverges from the selection's marker. But it never runs `clearSelectedCompany()`, so the identification-number/VAT-number fields the selection wrote (residual above) are untouched; the pre-submit sync, seeing an empty `companyid`, adopts the still-present, lookup-written `dni` as the organisation number and re-tags it with the retyped name — so the stale number reaches the POST anyway, refilled at submit rather than never cleared. Even setting that aside, the session-level backstop's protection does not reach this case: the resolver's fallback priority below the session cookie (`extractOrgNumberFromAddress()`) reads `dni`, then `vat_number`, then `companyid` **directly off the saved address**, independent of the session cookie the backstop just cleared — so a lookup-written `dni` or `vat_number` left in place by a retype is picked up there regardless of what the backstop did to the session. This residual is closed only where the address-lookup toggle was off for the whole session, so the lookup never wrote either field in the first place. Closing it generally is the wider retype-path work, tracked separately and out of scope here
  - **Arrow-keying onto the row cannot write into the company field.** The widget's focus event fires *after* the menu has already focused the row, and its return value gates only the write that mirrors a key-navigated item into the input — so refusing that write costs the row nothing in reachability, and not refusing it was a live defect: alongside real companies the row's empty value would have **blanked the term the buyer typed**, and alongside a message row the normalizer would have written the affordance text itself into the field. Either way the write bypasses the `input` event, so the stale-selection clearing never ran and the previous company's organisation number stayed behind a field reading empty or nonsense
  - **The row carries an explicit `aria-label`.** The widget announces a key-focused row through its live region as `aria-label` or else the item's value, and this row's value is empty whenever real companies are present — so without the label it was announced as *nothing* while looking perfectly correct on screen
  - **What the buyer must fill for manual entry to actually work.** An order cannot be placed without both a company name and an organisation number, and the plugin has always resolved the number from the address step's own identification-number or VAT-number field when no company was selected from the register — unverified, exactly as it sends a selected one. So a hand-typed company name plus a hand-typed identifier in that field is sufficient, and the number is validated by Two on the order-intent request rather than here. Where the merchant's country and B2B settings mean PrestaShop renders neither of those fields, manual entry gets the buyer no further than before — see the ticket
  - **Spanish added for both strings**, in the informal *tú* register the rest of that catalogue uses. The wording is identical across all four plugins by design
  - **New Jest coverage on both render paths**, aimed at the inversion rather than at the row's presence — see `tests/js/README.md`
  - **No migration and no new upgrade script**: browser JS, CSS, one translation catalogue and the server-side string dictionary. Nothing under `override/`, no configuration key, no database column, no template change, and no request shape changes

### Changed
- **The selected company's registration number is now shown inline, in grey, next to the field** (TWO-25288, umbrella TWO-24739)
  - Once a search result is chosen, a small grey `<span class="two-company-id-hint">` appears to the right of the company name field showing that company's org number, so the buyer can see which registration the plugin is about to submit without opening dev tools. It reuses this module's existing `#6b7280` muted-grey token (see `.two-info-label`) rather than introducing a second shade of grey
  - It clears whenever the hidden `companyid` field it mirrors is cleared: an edit to the company name, a country change, a re-selection whose result has no org number yet (e.g. GB, resolved a moment later via the details lookup), or `reset()`. It never drifts from the value that actually gets submitted
  - This plugin has no separate visible company-id/org-number field to hide — `TwoCompanySearch.js` already writes the org number into a `type="hidden"` input, and no `.tpl` template renders a company-id field to the buyer. So only the additive inline hint was needed here
  - No template change: inserted and positioned by `views/js/modules/TwoCompanySearch.js` alone. No migration, no configuration key, no database column, no request-shape change
  - New Jest coverage in `tests/js/company-search-rerender.test.js` (`the inline grey company-id hint (TWO-25288)`)
- **The company field now says what it wants, before and below the search threshold** (TWO-25288, umbrella TWO-24739)
  - **Empty field**: the placeholder now reads `Enter company name to search` (was `Search your company name`). The placeholder is the one slot an empty field has, so the wording there was replaced rather than joined by a second hint — a message row hanging under a field the buyer has not touched yet is noise, not help
  - **Below the threshold**: the dropdown now shows `Please enter 3 or more characters`. Previously it showed **nothing at all** — the widget simply never opened its menu — which to a buyer is indistinguishable from a search that ran and found no match, and that reads as "my company is not registered here". It is rendered by the same message-row mechanism as the existing "Searching…", "temporarily unavailable" and "select your country" rows, so it is non-selectable, skipped by keyboard navigation, and cannot be written into the field
  - **A fixed number, not a countdown.** A remaining-characters count changes on every keystroke, which reads as an error being repeatedly re-raised, and it has to be recomputed at every call site — which is exactly where a claimed threshold drifts from the enforced one
  - **One constant, and the message is interpolated from it.** `MIN_SEARCH_LENGTH` in `views/js/modules/TwoCompanySearch.js` is now the only place the threshold is written: it gates the request on both render paths and supplies the number the buyer reads. The translatable string deliberately keeps an unresolved `%d` rather than spelling out "3", so no translation catalogue can become a second source of truth and claim a threshold the code does not enforce
  - **The widget's own `minLength` is now 0, and that is not a loosening.** jQuery UI never invokes its `source` callback for a term shorter than `minLength`, so leaving the threshold there would make the new hint unreachable by construction. The threshold moved into the `source` callback, which is where the request is actually made and is therefore the only gate a request can pass through. Sub-threshold terms fire no network call — pinned by a test asserting zero requests
  - **Both render paths, not one.** The jQuery UI widget and the hand-rolled fallback list used by themes that ship no jQuery UI each gained the hint; covering only the first would leave half the installed base unchanged while the suite looked green
  - **Spanish added for both strings.** `translations/es.php` gains rows for the new source hashes, in the informal *tú* register the rest of that catalogue uses. The previous placeholder wording carried no Spanish entry, so nothing is orphaned by the change
  - **This one DOES need an upgrade script, and the reason is easy to get wrong.** No configuration key, no database column, no template change and no change to any request shape — but the empty-field hint lives in `override/classes/form/CustomerAddressFormatter.php`, and a module's `override/` directory is a **template**: PrestaShop copies it into the shop's own override tree once, at install, and from then on the shop's copy is the file that executes. Nothing rewrites it — not an upgrade, not a deploy, not a git-sync. So without a migration the new wording ships, the module reports the new version, the files on disk are correct, and **every existing shop goes on rendering the old placeholder indefinitely**. The browser JS does not rescue it either, because it only fills an *empty* placeholder — the stale override wins. `upgrade/upgrade-2.7.2.php` calls the override migrator, exactly as 2.7.1 did after this trap was found on a live shop in this same file
  - The payment-step fields are untouched — PrestaShop renders no company or organisation-number input there
- **The company-search spinner is the same loader GIF on every plugin, and the duplicate rule that overrode it here is gone** (TWO-25288, umbrella TWO-24739)
  - The four plugins each drew their own in-field search indicator. They are being standardised on the animated loader GIF this module already ships, set as the input's `background-image`, so the buyer sees one spinner wherever they check out. This module keeps its asset and its scoped rule; the other three gain a copy of the asset in their own PRs
  - **The GIF was named by TWO rules here, and the wrong one was winning.** Alongside the rule scoped to the company field there was a second, unscoped `.ui-autocomplete-loading` rule declaring `background: white url(...) !important` and `padding-right: 25px !important`. Being `!important` it out-ranked the scoped rule whatever the specificity, so the company field really did get a white box painted over the merchant's theme and a 25px gutter while a 32px one was reserved — and since both rules named the same GIF, the spinner looked correct and nothing gave it away. The unscoped rule also matched every other jQuery UI autocomplete on the page, which was never intended. It is deleted; the scoped rule now actually applies
  - **`background-image`, not an `<img>` tag.** An `<img>` would need a resolved URL plumbed through each platform's own asset helper; a `background-image` in a stylesheet resolves relative to the stylesheet, which is the same mechanism on all four
  - **No `prefers-reduced-motion` rule.** CSS cannot pause, slow or step an animated GIF, so a media query claiming to would be a literal no-op that misleads the next reader. There is deliberately no rule rather than one that appears to do something
  - The eligibility overlay's own CSS spinner is untouched — different element, different job
  - **New Jest coverage on both render paths**, and the first tests in this suite to load a real stylesheet: the class the spinner keys off was already asserted while the `!important` rule quietly overrode the paint, so only reading the resolved style catches that class of defect. One case also reads the asset off disk and checks it is a multi-frame 16x16 GIF, since jsdom resolves a `url()` naming a missing file exactly as happily as a real one. See `tests/js/README.md`, including two jsdom limits that make certain obvious assertions unable to fail
  - **No migration and no new upgrade script**: CSS and tests only. The loader GIF was already shipped, so no new asset. No browser JS, no configuration key, no database column, no template change, and no request shape changes
- **"No tax" is no longer offered as a surcharge tax treatment, and the field is now called "Surcharge Tax Treatment"** (TWO-25279, umbrella TWO-24739)
  - The dropdown offered PrestaShop's built-in `No tax` sentinel (tax rules group id `0`) alongside the merchant's own tax rules groups. That is a core default, not a rule the merchant configured, and selecting it silently means "the payment terms fee is never taxed, in any country" — a tax decision made by picking an option we handed them rather than by setting up a rule. A merchant who wants an untaxed fee now creates a tax rules group with a 0% rate and selects that, so the treatment is always visible and auditable in **Tax Rules**
  - **There is no grandfathering.** A shop that already stores `No tax` does not keep it in the dropdown, is refused when it tries to save it, and is told so plainly: the configuration page shows a loud error naming the consequence ("the surcharge is UNTAXED in every country") until a real tax rules group is selected. A silent zero that merely *looks* unset is the outcome this avoids — a `<select>` cannot render a value absent from its options, so without the error the field would simply appear unconfigured while the fee was in fact being charged untaxed
  - Server-side enforcement matches the dropdown exactly: the never-taxed treatment is refused by the form validator **and** by the save path (which runs even while surcharges are disabled, where validation does not), so removing the option is not a UI-only rule a crafted POST can walk past. All four sites — the option list, the validator, the save guard and the error notice — delegate to one predicate, so they cannot drift apart
  - **Label renamed** from `Surcharge Tax Rules Group` to `Surcharge Tax Treatment`, matching the Magento and WooCommerce selectors verbatim — one name for one decision across all three platforms. The help text no longer advertises `No tax` as a selection, and the post-upgrade "needs re-selection" notice names the new label
  - **No migration**, deliberately, and therefore no new upgrade script and no schema change: `PS_TWO_SURCHARGE_TAX_RULES_GROUP` keeps its name and its stored values. A merchant's tax configuration is never silently rewritten, so a shop still holding `0` keeps charging an untaxed fee — visibly, via the error above — until it chooses. Group `0` resolves `0.0` at runtime exactly as before
  - `translations/es.php` carries no entry for any of the surcharge configuration strings, so no translation is orphaned by the new source hashes, and the two new error strings are untranslated for the same pre-existing reason. That gap predates this change and is unaffected by it
- **The purchase-order field is now called "PO Number" everywhere** (TWO-25278, umbrella TWO-24739, reverses TWO-25271)
  - The admin toggle said `Show Purchase order number field` and the checkout field said `Purchase order number`, while the Magento plugin's checkout tile said `PO Number`. The same field therefore had two names depending on which plugin, and which pane, a merchant was looking at. All four plugins now use the single phrase `PO Number`
  - **Why the short phrase rather than the long one**: it leaves no stale translation key behind to maintain, carries no cosmetic-breakage risk for a shop upgrading, and renders better in the field. TWO-25271 had briefly gone the other way, standardising on the longer phrase; that is reversed here
  - Surfaces: the admin switch label, its help text, and the checkout tile's field label, plus the two README references. The switch keeps the `Show <x> field` pattern shared with the invoice email, project and department switches, which are unchanged
  - **Display copy only.** `PS_TWO_ENABLE_PO_NUMBER`, the `two_purchase_order_number` input name, the `purchase_order_number` internal key and the `buyer_purchase_order_number` payload field are all untouched, so no stored configuration and no request shape changes
  - **No migration**, and therefore no new upgrade script: nothing is persisted and no configuration key is added or renamed
  - `translations/es.php` carries no entry for any of the three changed strings, so no translation is orphaned by the new source hashes. The gap for Spanish predates this change and is unaffected by it

### Fixed
- **A surcharge cap of 0 is refused at entry, and a stored 0 is no longer relayed as "no cap"** (TWO-25289, umbrella TWO-24739)
  - **The overcharge.** `TwoSurchargeCalculator::buildBuyerFeeShare()` tested the configured cap with `> 0`, so a cap of exactly `0` was normalised to **absent** — and absence is what means "uncapped". A merchant who configured `0` therefore got an **uncapped** percentage surcharge: the opposite of the instruction, and an overcharge to the buyer. It is now relayed as `cap => 0`, which bounds the fee at zero
  - **Absent and zero are now distinguishable at the settings boundary too.** `getTwoSurchargeSettings()` cast the cap unconditionally, and an unset `Configuration` key reads back as `false` with `(float) false === 0.0`, so "no cap configured" and "a cap of zero" were the same value before the calculator ever saw them. A blank cap now reads back as `null`; anything numeric, zero included, is a real configured cap. The save path already stores `''` for a blank cell, so blank is the honest unconfigured signal
  - **Refused on save.** `validTwoSurchargeFormValues()` now rejects any cap that rounds away at 2dp — `0`, `0.0`, `0.00`, `00` and sub-cent values like `0.001` alike, since the cap is rounded before it is sent and a sub-cent cap would otherwise arrive as a hard `0.00` one step later. `0.005` rounds *up* to `0.01` and survives. The error says what to do instead. A cap of `0` is never what a merchant means by it: the cap bounds the **whole fee** — the percentage and the fixed fee together, not the percentage alone — so it silently wipes a configured fixed fee too, and the intent it gets mistaken for is expressible directly with 0% and a 0 fixed fee. A **blank** cap stays valid and still means "no cap"
  - **No migration**, and therefore no new upgrade script: no configuration key is added, renamed or rewritten. An already-stored `0` is not migrated — it now relays as a genuine zero cap, i.e. no fee, which is the safe reading of it. **Consequence, stated plainly:** on a shop that already has a cap of `0` (or a sub-cent one) stored against a term, the Payment Settings tab cannot be saved while the cap column is visible until that cell is cleared or given a real value — the save aborts on any validation error. The cell is visible and the error names the term, so it is actionable, and the rule is skipped entirely while the column is hidden so a fixed-only or disabled shop is never blocked
  - **Monetary values are rounded to 2dp before the request.** The pricing API refuses a value finer than two decimal places rather than rounding it, so an over-precise configured `surcharge` or `cap` was rejected upstream and surfaced to the buyer as a generic error. The cross-currency path already rounded at `convertTwoAmountBetweenCurrencies()`; the same-currency path did not, and now does. Plain half-up rounding — sub-cent caps, away-from-zero rounding and zero-decimal currencies are all deliberately out of scope, since the one value where the rounding direction would have mattered is now refused outright
  - **Admin copy.** The grid's cap column heading said `Cap on percentage`, which describes it wrongly; it is now `Cap`, with a note under the grid stating what the cap actually bounds and that `0` is not allowed
  - **Validation covers every RENDERED term, not the stored ticked subset.** The grid posts a row per offerable term, and the ticked subset is rewritten earlier in the same save than the surcharge values are read — so a cap typed on a term ticked in the same submit was stored without ever being validated, then relayed. An unsubmitted cell is now skipped explicitly rather than being reported as a non-numeric value
  - **A zero cap never withholds the payment method.** `isTwoSurchargeQuotableForCart()` fails closed on a missing FX rate and loops every offered term, so relaying a zero cap instead of dropping it would have taken Two offline for every buyer on a shop whose only currency-bearing member was a zero cap — over a conversion with no work to do. Zero needs no rate: it is zero in every currency, and is now skipped in `convertTwoBuyerFeeShareCurrency()`. This is the TWO-25276 regression shape reached by a different route, and it is covered by its own test
  - **Non-numeric and negative stored caps are absent, not zero.** Both would have cast to a real cap (`0.0` and `-10.0` respectively) and either suppressed the fee on a previously-uncapped shop or been refused upstream
  - **The cap help text is hidden with the cap column.** The column-visibility JS was scoped to the grid table; it now covers the whole form-group, so cap-only copy is not left on screen for a fixed-only surcharge
  - `translations/es.php` gains the four new/changed strings. That file carries no other surcharge-grid entry — a gap that predates this change and is untouched by it
- **Reverted: the zero-cap "overcharge" guard withheld Two from every buyer on affected shops** (TWO-25276, reverting part of TWO-25269, umbrella TWO-24739)
  - **The premise of the guard was false.** TWO-25269 (entry below) added a check in `convertTwoBuyerFeeShareCurrency()` that failed closed when a **configured** cap converted to `0.00`, on the stated reasoning that "a zero cap is indistinguishable downstream from *no* cap, so passing it through sends an uncapped percentage - an overcharge". The API does not behave that way: it tests the relayed cap for **presence**, not truthiness, an absent cap is what means "no cap", and its own test suite pins the zero-cap outcome. A zero cap therefore **bounds the fee at zero** (source references are recorded on TWO-25269 rather than quoted here - this repository is public and that service's is not): the surcharge is simply not applied, which is exactly what capping at zero asks for. There was never an overcharge to guard against
  - **The guard had a real, immediate cost.** `isTwoSurchargeQuotableForCart()` loops **every** offered term, and any single term whose configured cap rounded to `0.00` returned false, so `hookPaymentOptions()` returned `[]` and the Two payment method was withheld from **every buyer on the shop**. A behaviour regression on `staging`
  - **Reverted**: the `cap`-specific rounds-to-zero branch and its error log are gone; a converted cap of `0.00` now passes straight through as `cap => 0`. The quotability gate trips on the **no-FX-rate condition only**, which is term-independent and remains the correct fail-closed case
  - **Deliberately kept from TWO-25269**: the `isTwoSurchargeQuotableForCart()` gate itself and the `hookPaymentOptions()` early return for the no-rate case; the error-level no-rate log naming the currency pair and term (the fail-closed path previously threw with nothing logged at all); the fixed-`surcharge`-rounds-to-`0.00` case proceeding at `0.00` with an info log; the absent-cap skip (an absent cap still sends an uncapped percentage, by design); and the `applyTwoSurchargeCartLineSync()` fix that distinguishes an unavailable quote from a deselection
  - **No rounding was changed.** The pricing API rejects a monetary value finer than two decimal places rather than rounding it, so an unrounded figure is a validation error, not a more precise cap. Every `round($converted, 2)` stays
  - **No migration.** No configuration key and no database column, so no upgrade script and no version bump

- **A buyer surcharge that could not be priced in the cart currency was silently not charged at all** (TWO-25269, umbrella TWO-24739)
  - **This was believed to be safe and was not.** The previous reasoning was that PrestaShop was "already fail-closed because the quote is omitted". Both halves of that were wrong. `hookPaymentOptions()` never consulted the surcharge, the fee quote or FX at all - between the minimum-order gate and the return there was nothing but an unconditional "payment option shown" log. And `applyTwoSurchargeCartLineSync()` treated "quote unavailable" as equivalent to "surcharge deselected", removing the hidden surcharge cart line and returning **success**
  - **End-to-end consequence:** on a store whose cart currency had no FX rate against the currency the surcharge is configured in, Two was offered normally, the hidden surcharge line was deleted, and the order was created with a **zero surcharge and nothing logged**. Silent undercharge, on every affected order, with no signal that anything had happened
  - **Fix**: a new `isTwoSurchargeQuotableForCart()` gate withholds the payment option, reusing the existing mechanism - `hookPaymentOptions()` returns `[]`, exactly as the minimum-order gate already does. The checkout is never errored; Two simply is not offered. `applyTwoSurchargeCartLineSync()` now distinguishes an unavailable quote from a deselection: it leaves the cart line in place, reports failure and logs, instead of removing the line and claiming success
  - **The gate condition is deliberately term-independent.** No term is selected when payment options render, so the gate cannot ask whether the *chosen* term is quotable - and it does not need to, because the rate lookup for the (configured currency → cart currency) pair fails identically for every term. Gating on "any offered term is unquotable" would have taken a whole store offline over one misconfigured term. The condition is: surcharge enabled **and** cart currency differs from the surcharge's configured currency **and** at least one offered term carries a fixed or cap component **and** the rate is unresolvable. A percentage-only grid is currency-agnostic and never trips it
  - **Three rounds-to-zero cases, and PrestaShop previously detected none of them.** No rate resolvable → **fail closed**, error log. A **configured** cap whose converted value rounds to `0.00` → **fail closed**, error log. ⚠ **This second guard was reverted immediately afterwards under TWO-25276 - see the entry above. Its premise was false and it caused a live outage.** A **fixed** amount whose converted value rounds to `0.00` → **not a failure**: it is a legitimately tiny configured amount, genuinely negligible in a stronger currency, and `0.00` is the arithmetically correct answer. It proceeds, logged at info
  - **An absent cap is not a failure and never was.** "No cap defined" is a legitimate configuration meaning an uncapped percentage surcharge, and it continues to be charged normally with Two still offered
  - **Signals, where there were none.** `convertTwoAmountBetweenCurrencies()`, `convertTwoBuyerFeeShareCurrency()` and `fetchTwoTermFee()` logged nothing whatsoever, which is why this went unnoticed. Every fail-closed path now logs at error level naming the currency pair and the term; the negligible-fixed-amount case logs at info. The generic converter stays deliberately silent because it is shared with fail-soft display callers - the decision sites log instead
  - **The per-term chip previews stay fail-soft, deliberately.** `getTwoOfferedTermSurchargeAmounts()` is display, not charge: it shows a number, it never decides one, so a failed preview degrades that one chip rather than removing the payment option. This matches the Magento plugin, whose preview paths also degrade while only the authoritative total path fails closed
  - **No migration.** The change adds no configuration key and no database column, so there is no new upgrade script

- **A shop kept running the old copy of a changed override, forever and silently** (TWO-25265, umbrella TWO-24739)
  - A module's `override/` directory is a **template**, not deployed content. PrestaShop copies it into the **shop's** own override tree once, at install or reset, and from then on the shop's copy is the file that executes. Nothing rewrites that copy - not an upgrade, not a deploy that replaces the module directory, not a git-sync, not a module reset. `Module::addOverride()` cannot do it even when it runs: for every method the shop's copy already declares it **throws** rather than replacing, and it has no path that removes one
  - **Consequence, and it applies to every existing shop:** 2.7.0 changed `override/classes/form/CustomerAddressFormatter.php` to stop injecting the department and project fields into the billing address block (they moved into the payment tile). The module's copy changed correctly and shipped correctly. Every already-installed shop went on running the old one. Observed on a live staging shop: module version reported `2.7.0`, files on disk `2.7.0`, deploy green, and a `2.4.0`-stamped override still injecting both retired fields into the address form. The same shop lineage backs the shop that tracks the release branch, so the identical symptom was queued up for the 2.7.0 release
  - **Fix**: `upgrade-2.7.1.php` calls the new `TwoOverrideMigrator::refresh()`, which deletes the shop-level copy of each of the module's overrides when it is stale, rebuilds the class index, and re-runs `installOverrides()` so the current version's copy is written fresh. A **retired** override is simply never re-written, so retirement and change are the same code path rather than two
  - **It refuses to delete three kinds of file, deliberately.** A file carrying any **other** module's `module:` stamp - PrestaShop's `override/` tree is a *shared* merge target and several modules splice methods into one file, so deleting a co-owned file would silently uninstall someone else's override, a worse failure than the one being fixed. A file carrying **no** stamp, which is core's own or a merchant's hand-edit. And a file whose every stamp already reads the installed version, which is what makes a second run a genuine no-op. Co-owned and unreadable files are logged for a human, never touched
  - **It cannot fail an upgrade.** Every filesystem operation is guarded and the upgrade function returns true unconditionally: a shop that cannot be tidied must still finish upgrading
  - **Why a new version rather than an edit to `upgrade-2.7.0.php`**: PrestaShop discovers upgrade scripts **by filename** and runs them only for versions strictly above the installed one. The shop with the bug is already **on** 2.7.0, so anything appended to that script would never run there - `number_upgraded=0`, silently
  - **New CI gate so the convention cannot decay**: `.github/scripts/check-override-migration.sh` fails a pull request that changes or retires a file under `override/` without an upgrade script that migrates it, and is itself tested by `.github/scripts/test-check-override-migration.sh`. What it cannot see is written down in its header - most importantly, it reads the repository and never a shop, so it stops new instances of this defect and does not discover existing ones
  - **Not fixed here, because nothing in this repo can:** `.tpl` changes go stale on a shop for a different reason (a compiled Smarty template is never regenerated while `PS_SMARTY_FORCE_COMPILE` is `0`). That is shop configuration, handled chart-side

### Changed
- **Optional buyer reference fields moved into the Two payment tile, two more added, all four ON by default on a fresh install** (ABN-472, umbrella TWO-24739)
  - **Defaults: all four ON for a fresh install; existing shops keep whatever they had.** `install()` writes `PS_TWO_ENABLE_INVOICE_EMAIL`, `PS_TWO_ENABLE_PO_NUMBER`, `PS_TWO_ENABLE_PROJECT` and `PS_TWO_ENABLE_DEPARTMENT` to `1`. `upgrade-2.7.0.php` **seeds each of the four only when the key is absent** and never overwrites a stored value - a stored value is treated as the merchant's choice regardless of how it got there, which is the call the WooCommerce plugin makes
  - **Expected consequence for existing shops, accepted rather than a bug:** on department and project the upgrade is close to a **no-op**. The admin form has always saved both keys on every submit, so practically every live shop already carries a stored `0` - a `0` that came from `install()` never writing a default (the switches therefore rendered off) rather than from a decision. **Those shops keep department and project OFF until a merchant enables them; only the two new fields, invoice email and purchase order number, appear at checkout after upgrading.** A near-empty upgrade log line is the correct outcome
  - **Placement.** All four now render inside the Two payment option's tile at the payment step. Department and project used to be injected into the **billing address** block by the `CustomerAddressFormatter` override. PrestaShop collects the **shipping** address first and only reveals the billing block when the buyer ticks "Billing address differs from shipping address", so both fields were invisible to most buyers - the same field in the same place as WooCommerce, opposite outcome, purely because the two platforms order the address steps differently. The invoice email is in the tile for a second reason on top of that one: it has to be visible in the case where billing and shipping **match**, because that is exactly when the buyer should be prompted to consider a separate address for invoices
  - **Two new fields**: purchase order number (`PS_TWO_ENABLE_PO_NUMBER`) and invoice email address (`PS_TWO_ENABLE_INVOICE_EMAIL`). Neither is required; an empty field is not sent
  - **Payload**, matching the WooCommerce plugin's names and shapes: `buyer_department` and `buyer_project` are always-present scalars, so they stay as empty strings when unfilled; `buyer_purchase_order_number` and `invoice_details.invoice_emails` are added only when the buyer filled them in. An invoice email that is not an email is dropped and logged rather than failing the checkout - the buyer-side script rejects it before submit, and refusing an order over an optional field is the worse failure
  - **Transport.** PrestaShop renders a payment option's additional-information block as a **sibling** of the option's form, so a visible input in the tile is not part of the submission. Each enabled field therefore declares a hidden twin among the payment option's inputs, and `views/js/modules/TwoOptionalFields.js` mirrors the value across on input and again on submit. A disabled field declares no twin, renders no element at all (not a hidden one), and its value is never read from the request even if the parameter is supplied by hand
  - **Nothing was ever persisted by the old placement**, so there is no data to migrate: `department` and `project` were added to the address form but PrestaShop's address table has no such columns, so the values were discarded and the order payload - which read them off the `Address` entity behind a `property_exists()` check that could never be true - always sent empty strings. From this release the values reach Two on order creation
  - **Known limitation, unchanged from before**: the order **update** payload (admin order edits, provider webhooks, status transitions) still sends `buyer_department` / `buyer_project` empty and no PO number or invoice email, because none of those contexts carry the buyer's payment-step submission and the values are not persisted locally. The `property_exists()` reads there are now explicit empty strings with that reason written down
  - **Order comments are core's field, now relayed.** No plugin order-note field was added and none should be: PrestaShop's own "add a comment about your order" textarea (`name="delivery_message"`) on the checkout **shipping** step already is one, stored by core one row per cart in the `message` table. The module now sends it to Two as `order_note` on order creation **and** order update, capped at 1000 characters, decoded back to plain text because core stores it htmlentities-encoded (`Tools::safeOutput`). It is read from the **cart**, not the buyer's submission - which is what lets the update payload carry it too; read from the request, an admin order edit would blank the note on Two's side. Previously `order_note` was hardcoded empty
  - **Deliberately NOT added to the order-intent payload.** `order_note` has no bearing on a credit decision, the WooCommerce plugin does not send it on its intent call either, and `/v1/order_intent` is a buyer-blocking path - so it is not the place to find out whether an extra field is tolerated
  - **Standard field order**, applied to the admin switches and the checkout tile alike so the pane reads like the thing it configures: invoice email, purchase order number, project, department, order note. The order note is fifth in that sequence but has **no expression in the tile** - it is core's field on a different checkout step - and no plugin field was invented to give it one
  - Module version bumped to `2.7.0`

### Removed
- **Redundant organisation-number pre-verification on the checkout path** (TWO-25206, umbrella TWO-24739)
  - When a logged-in buyer checked out against a saved billing address, the module pulled an organisation number out of that address (`dni`, `vat_number`, `companyid`) and then made its own blocking, unauthenticated `GET /companies/v2/company?q=<orgnum>&country=<iso>` call - a 30-second timeout budget on the buyer-blocking order-intent AJAX - before it would let the buyer pay with Two. Neither the WooCommerce nor the Magento plugin has an equivalent step
  - The call was redundant. Two validates the organisation number's format and checksum per country and resolves it against the company registry synchronously on the same `/v1/order_intent` request this handler builds the payload for, rejecting the intent before it is created when it does not resolve. It also overwrites the company name from the registry, so any name the module resolved locally was discarded and re-derived anyway. The module's own payload path has always sent this organisation number unverified, so the pre-check never guarded the payload - only its own prompt
  - It was also actively harmful. The pre-check used the **fuzzy** company-search endpoint while order intent uses the **exact** by-organisation-number one, so a company Two resolves fine could fail the pre-check and the buyer was hard-blocked with "go back to your billing address and search for your company name". A slow or unreachable provider was indistinguishable from "this company does not exist" - both blocked. And when the fuzzy search returned no exact match but exactly one result, the module accepted **that** company, whose organisation number differed from the one searched, and cached it as the buyer's verified identity
  - The organisation number found on the address is now handed to Two as-is, and Two is the only thing that verifies it. It is deliberately not treated as search-verified anywhere it was not before
  - Removed with it: `verifyCompanyByOrgNumber()`, the `getTwoVerifiedCompanyForOrgNumber()` wrapper and its `two_company_verify_miss` cookie memo (added by TWO-24799 to make the miss path survivable), `extractOrganizationNumber()`, and the `COMPANY_VERIFY_MISS_CACHE_TTL` constant. `extractOrgNumberFromAddress()` is untouched - it is still how a saved-address organisation number reaches Two
  - No configuration key was removed, so this release needs no upgrade script
  - Module version bumped to `2.6.7`

### Added
- **JS test harness for the module's front-office JavaScript, plus company-search regression tests** (TWO-25239, umbrella TWO-24739)
  - The company-search rewrite produced three defects in successive review rounds - a spinner that never came down, a `_renderItem` wrapper that nested a layer deeper on every address-form update until rendering a row blew the stack, and a destroyed instance that still wrote the selected company's organisation number into a detached `companyid` field. **None of the three were reachable by the PHP suites**, because all three live in `views/js/`. Verifying them meant throwaway node scripts against copy-pasted logic
  - `tests/js/` is Jest + jsdom, gated in CI as the `jest` job in `.github/workflows/tests.yml` and run locally with `make test-js`. Layout mirrors `magento-plugin`'s `Test/Js/`: a root `package.json` holding only JS devDependencies (`package-release.sh` already excluded `package.json` and `package-lock.json`, so nothing had to change there), a jest config beside the tests with `rootDir` back at the repo root, and `testEnvironment: 'jsdom'`. Test files glob, unlike `tests/run.php`'s explicit `require` list
  - **No production code was changed.** `views/js/modules/*.js` are plain classic scripts that hang a class off `window`, so the harness evaluates them in global scope exactly as a `<script>` tag would, with the **real** jQuery and the **real** jQuery UI autocomplete widget installed on the jsdom window and a small stub for PrestaShop's `prestashop` event bus - deliberately one with no `off`, because the real bus has none either, which is the whole reason the module needs a `_destroyed` flag
  - **The real widget rather than a mock, deliberately.** Two of the three defects are properties *of jQuery UI*, not of module code: its widget bridge reuses an already-initialised instance instead of building a fresh one when `.autocomplete({...})` is called again (so a wrapper applied per setup nests), and it clears `ui-autocomplete-loading` only when a search's `response()` callback actually runs (so a dropped callback leaks the spinner). A mock would have to reproduce both correctly to catch either bug - which is the assumption that let them ship
  - Coverage: `responseCallback` fires **exactly once** per search on all twelve paths (short term, success, timeout, network error, parser error, a failure with no textStatus at all, abort, superseded, backspacing under the minimum with a request live, stale-success-outrunning-its-abort, stale-failure, teardown mid-search); a failure reports `unavailable` and never an empty result set; abort stays silent while a timeout does not; `degraded === true` strictness including absent-means-false and every truthy non-`true` value; the `_renderItem` patch surviving 100 re-setups, 20 country changes and 20 `updatedAddressForm` events as the same function by reference; the unavailable row being non-selectable and text-only; a company selected through the real widget landing in every field it should; the stale-selection clearing that runs on every re-render; the pre-submit identifier sync; and the company-detail fill - the authoritative number overriding the search's, the selection marker written with it, and the input/change events the theme listens for; a destroyed instance writing nothing; and the custom no-jQuery-UI fallback's spinner and orphan-container behaviour. `tests/js/README.md` has a **Known gaps** section naming what is deliberately uncovered - the order-intent recheck and cookie persistence among it - so the list above is not read as "company search is covered"
  - `tests/js/README.md` documents the harness. `tests/README.md`, `README.md`, `AGENTS.md` and `AI_CONTEXT.md` now list `make test-js` alongside `make test`

- **Per-brand off switch for the order-intent approved notice** (TWO-25218, umbrella TWO-24739)
  - Two **separate** keys in `brands/two.php`, because on/off and wording are unrelated decisions and must not share a key
  - `intent_approved_notice_enabled` - the switch, an explicit boolean **only**, resolved by `Twopayment::isIntentApprovedNoticeEnabled()` and handed to the checkout JS as `window.twopayment.intent_approved_notice_enabled` (a real JS boolean). `true` shows the notice; `false` suppresses it entirely, rendering no element at all, not even an empty wrapper; an **absent** key is the documented default `true`, which is what keeps a third-party overlay that declares nothing on. Any non-boolean value is a clear logged error (`PrestaShopLogger`, severity 3, naming the key, the offending value's type and the brand code) and then the default `true` - deliberately not a throw, because this resolves while rendering a buyer-facing checkout where a white screen is a worse failure than a notice that stays on, and `true` is the fail-safe direction
  - `intent_approved_notice` - the copy override **only**, resolved by `Twopayment::getIntentApprovedNotice()`. A non-empty string is used verbatim as the company-variant template, where `%s` is the buyer's company name; absent, `null`, empty or whitespace-only all mean the platform default translated copy. An override replaces the company variant only - the no-company copy stays default
  - **Empty is now inert.** Under the superseded TWO-25213 design an empty `intent_approved_notice` was how you turned the notice off; it no longer does anything. That old meaning is what a reader will remember, so it is called out in the brand-file comments and the resolver docblocks. Nothing about the copy key can switch the notice off
  - Both approved-message render sites gate on the boolean, never on the falsiness of the copy string: the inline `.two-order-intent-message` in `TwoOrderIntent` (`processResult()` and `updateUI()`) and `TwoCheckoutManager.showOrderIntentApproval()`. In both consumers an absent or non-boolean payload value reads as **enabled**, so an older cached JS file or an older template that never carried the key can never mean off
  - Only the **approved** notice is switched. Declined and error messages are functional and always render, and order prevention on decline is untouched
  - Migration hazard, documented rather than "fixed": a new module against a stale overlay that still carries an empty `intent_approved_notice` and no `intent_approved_notice_enabled` resolves to notice **on** - wrong for that brand, but not broken, and fixed by declaring the boolean. Making an empty copy value a hard error would turn that window into a broken store instead, so no legacy-compat path resurrects empty-means-off
  - No `Configuration` key is added and no upgrade script is needed, so this carries no version bump of its own

- **Merchant toggle for the checkout address lookup** (TWO-25203, umbrella TWO-24739)
  - The company address lookup on the checkout address step was unconditional: picking a company from the company search always overwrote the address fields (street, postcode, city) and the organisation-number fields (DNI, VAT number). The other plugins each let the merchant turn that off; this one did not
  - New `PS_TWO_ADDRESS_LOOKUP` switch on the advanced-settings page, **enabled by default**. With it off the company search still runs and still records the company name and organisation number - the Two flow needs those - but nothing is written into the address or identifier fields
  - The fill semantics are unchanged and remain deliberate: with the toggle on, re-searching and picking a different company overwrites both the address and the organisation number with the new company's values
  - Separate from the existing "Activate company name auto-complete" switch (`PS_TWO_ENABLE_COMPANY_NAME`), which gates the search widget itself. Neither key was reused or widened
  - Existing installs are behaving as "on", so the 2.6.6 upgrade script seeds the key to `1` - only when absent, so a merchant's saved opt-out survives a re-run - and every reader resolves an absent or empty row to enabled, meaning an install whose upgrade has not run yet cannot silently lose the fill
  - Module version bumped to `2.6.6`

### Fixed
- **Company search gave up exactly when the server would have answered, and reported every failure as "no companies found"** (TWO-25231, umbrella TWO-24739)
  - **Client timeout raised from 10s to 30s.** The server's own retry envelope is `stop_after_delay(10)`, so a 10-second client timeout aborted the request at the precise moment a successful response would have arrived. The buyer was shown a failure for a search that had in fact just succeeded. The client's ceiling has to sit clear of the server's, not on top of it
  - **A failed search no longer renders as an empty dropdown.** The `error:` handler called `responseCallback([])`, which made a timeout, a 5xx, a network error and a genuinely empty result set completely indistinguishable at the UI. An empty dropdown reads to a buyer as "my company is not registered" - a reason to abandon checkout rather than to retry - so all four rendered as the worst possible interpretation. Real failures now show an explicit "search is temporarily unavailable, please try again" row. A **genuine abort stays silent**, because that is routine operation (the buyer typed another character, or the address form was re-rendered) and the replacement request drives the UI
  - **Failures are never cached.** A cached failure would keep showing the buyer an error after the service had recovered
  - **New `degraded: true` response flag is honoured.** The company-search endpoint can answer HTTP 200 with an empty or partial body when its upstream registry provider timed out, and flags that case. Read as `=== true` so an **absent** field is false: every response predating the flag lacks it, and the server side may not be deployed yet. Degraded *with* results still renders the results - partial data beats an error message - and only degraded with nothing to show produces the unavailable row
  - **In-field spinner restored on the code path that actually runs.** `renderLoading()` existed but only on the custom fallback path, and the spinner CSS was commented out, so on the live jQuery-UI path a slow-but-alive search was visually identical to a dead one. The rule is scoped to the company field itself rather than to every autocomplete in `.js-address-form`, and sets `background-image` rather than the `background` shorthand so it no longer forces a white background over the merchant's theme
  - **The 5-minute result cache now survives widget teardown.** It lived inside the `setupAutocomplete` closure, and `TwoCheckoutManager.handleAddressFormUpdate()` destroys and re-creates the widget on every `prestashop.on('updatedAddressForm')` - which PrestaShop fires for ordinary interactions such as changing country. Every one of those re-renders therefore restored a cold cache and the buyer re-paid a full API round trip for a term searched moments earlier. Moved to module scope, with TTL pruning and a 50-entry cap it did not previously need now that it outlives the widget. **Aborting the in-flight request on teardown is unchanged and still correct** - a response whose widget no longer exists has nowhere to render; only the cache is preserved
  - Debounce was already 300ms on both paths, matching the Magento and WooCommerce plugins; verified and left alone
  - **Re-setup of the company search is now idempotent**, which self-review found it was not. `setupAutocomplete()` is re-invoked from both the country-change listener and the `updatedAddressForm` handler with no intervening `destroy()`, and three things went wrong each time: the `updatedAddressForm` handler re-registered itself so the count **doubled on every event** (making the work per event grow exponentially, with none ever unregistered); the custom fallback path inserted another dropdown while leaving the previous one's input listener firing; and the field object cached by `init()` was reused even though PrestaShop **replaces** the address form's DOM on that event, so bindings landed on a detached input. Registration is now guarded, the fallback path tears the previous one down (unbinding its listeners, which removing the container never did), and the field is re-resolved before use
  - **A destroyed company-search instance can no longer act on the live field.** `TwoCheckoutManager.handleAddressFormUpdate()` destroys the instance and builds a fresh one on every `updatedAddressForm`, but the handler the instance registered on that same event **cannot be unregistered** - `prestashop.on` has no `off` - so a destroyed instance still gets called. That was harmless only for as long as it held a stale field object and its work landed on a detached node; once re-resolving the field against the live DOM was added above, the destroyed instance resolved to the **same live input** as its replacement and re-bound the widget with its own stale closures. Because its `organizationField` is still the detached hidden `companyid` input its own `init()` created, the selected company's organisation number was written somewhere that no longer submits - recoverable only when the address-lookup toggle is on and a live `dni` field exists to copy back from, and silently lost otherwise. `destroy()` now marks the instance and every event-reachable entry point stands down. The per-instance registration guard stops one instance registering repeatedly but not a new instance per event registering once each, so the handler count still grows by one per address-form update; they are inert after the check, which is what makes that acceptable
  - Teardown of the custom dropdown also clears its spinner class, is no longer skippable by an earlier failure in `destroy()`, and the destroyed check now covers every entry point reachable from an event - the country-change setup and its pending retry, the fallback path's input listener, and company selection itself. Selection through a destroyed instance would write the organisation number to a detached input, so it stands down rather than writing
  - `destroy()` unbinds the country listener from the element it actually bound. It re-queried only `select[name='id_country']`, while setup picks the first of five fallback selectors - so on a theme matching one of the others the listener was never removed, leaking a live handler per address-form update
  - The pending debounce timer is cleared on teardown. A tick surviving teardown called into the search, which bumped the sequence and aborted the **new** dropdown's in-flight request - that request then resolved as superseded, so its spinner was never cleared and the buyer was left on a permanent "Searching..." row
  - A widget left on a node the field moved off is released rather than abandoned, along with the `<ul class="ui-autocomplete">` menu jQuery UI appends to `document.body` rather than beside the input
  - jQuery UI is detected via `$.ui.autocomplete` rather than `$.fn.autocomplete` alone - the older bassistance plugin of the same name has an incompatible signature, and feeding it the options object left the field with no working search while also skipping the fallback
  - No `Configuration` key is added and no upgrade script is needed, so this carries no version bump of its own
- **Documentation named the wrong include site for `defines_custom.inc.php`** (TWO-25217)
  - The code comment, README and design doc introduced with TWO-25200 all stated that `config/defines_custom.inc.php` is required by `config/defines.inc.php`. It is not: `config/config.inc.php` includes it, and does so *before* `defines.inc.php` (verified in a PrestaShop 8.2.7 container, `config/config.inc.php:30`). Anyone following those docs to find the include site would have concluded the mechanism did not exist
- **The order-intent loading overlay is suppressed with the approved notice** (TWO-25224, umbrella TWO-24739)
  - `intent_approved_notice_enabled => false` (TWO-25218) turned off the approval sentence but left the overlay shown while the pre-check runs. That overlay carries our own copy - "Checking Two payment eligibility..." - so a brand that had opted out of the reassurance messaging was still announcing the check to the buyer
  - `TwoCheckoutManager.showOrderIntentLoading()` now renders nothing when the switch is off. The scope call: the switch governs the buyer-facing **reassurance messaging** around the pre-check, so the approval notice and the loading overlay switch together, while `showOrderIntentDecline()` and `showOrderIntentError()` are deliberately **not** gated - a merchant who wants no reassurance still needs failures surfaced, or a declined buyer sees nothing at all
  - `isLoadingUIShown` is still set when the overlay is suppressed. It is the in-flight guard that stops the periodic selection check and the step-change handler firing a second intent, not a statement about the DOM; skipping it would double-fire the intent for suppressed brands
  - Same defect was fixed on WooCommerce. Magento's Luma renderer leans on the platform's own generic full-screen mask (no Two copy in it) and the Hyvä renderer shows nothing at all, so neither was changed - the Luma/Hyvä in-flight divergence is a pre-existing parity gap and is deliberately not touched here
  - Module version bumped to `2.6.9`
- **A rejected order intent now tells the buyer what to fix** (TWO-25206)
  - The browser calls `/v1/order_intent` directly and the error handler read only jQuery's status text ("Bad Request"), never the JSON body. Every 4xx therefore collapsed into the generic "cannot be approved at this time, please select an alternative payment method" - including `COMPANY_NOT_FOUND`, which is the response to an organisation number that does not resolve against the company registry and is precisely the case the removed pre-check used to catch locally
  - `error_code` and `error_message` are now carried out of the response body, and `COMPANY_NOT_FOUND` maps to the same instruction the pre-check used to show: go back to the billing address and search for the company. The message is the existing translated `select_company_to_use_two` string, matched ahead of the broader keyword branches so the wording cannot drift into a vaguer one
- **Company search now bounds its result set** (TWO-25192)
  - The request to `GET /companies/v2/company` sent only `q` and `country`, so the API's own default page size decided how many rows came back. A common company name in a large country returned an unbounded list into the autocomplete dropdown
  - The request now carries `limit` (50, matching the Magento and WooCommerce plugins) and `offset` (always 0). As on both of those platforms there is no load-more or next-page control - the first page is the whole result set - so the dropdown is capped rather than paged, and the existing scroll containers handle the rest
  - The limit is overridable through the module's JS config (`companySearchLimit`); the default lives on `TwoCompanySearch.DEFAULT_COMPANY_SEARCH_LIMIT`
- **Checkout failures now reach AJAX checkout front-ends instead of vanishing** (TWO-24768)
  - The payment controller's only failure signal was a 302 back to the order page with the message flashed into the session. A checkout that posts the payment form over XHR rather than navigating (PrestaShop's own checkout module does) follows that redirect, receives the order page HTML with HTTP 200, cannot distinguish it from success, and leaves the buyer on a checkout that never moves
  - AJAX callers (identified by `X-Requested-With: XMLHttpRequest`, or an `Accept` that asks for JSON and does not accept HTML) now receive `HTTP 400` with a JSON body carrying `error`, `message` and `redirect_url`. Ordinary browser form posts keep the existing redirect-with-notification behaviour unchanged
  - Every failure exit in the payment controller now goes through the single `failCheckout()` boundary. Five of them - including the provider-rejection path that produced the reported silent hang - previously redirected inline and so bypassed it entirely
  - Failures that deliberately carry no buyer-facing text (internal errors logged for the merchant) now emit a generic message to AJAX callers rather than an empty string, so the caller always has something to render
- **"Two: Order Fulfilled - Trigger Statuses" multi-select now renders its saved selection** (TWO-24769)
  - PrestaShop core's `HelperForm::generate()` rewrites a multi-select field's name in place (`$params['name'] .= '[]'`) and the admin form template then resolves the pre-selection with `$fields_value[$input.name]`, i.e. under the `[]`-suffixed key - verified identical in PS 1.7.6.5, 8.x and 9.x. The module populated only the plain key, so every option rendered unselected even though the stored value was correct (display-only; fulfillment triggering reads the configuration directly and was never affected)
  - The pre-selection IDs are normalised to strings, matching the `id_order_state` the option values are built from, so the comparison no longer relies on PrestaShop's loose `==` in the template
  - The custom-order-state recovery path (`ensureCustomStatesExist()`, which fires when Two's own order states are missing) wrote `PS_TWO_OS_FULFILLED_MAP` as a bare status ID rather than the JSON array every other writer uses - three divergent storage formats for one key. It now writes the canonical JSON array, and the 2.6.2 upgrade script normalises any value a store is already holding in the legacy format
  - Module version bumped to `2.6.2`
- **Shipping amount and shipping VAT rate now come from the cart, not from a loadable carrier** (TWO-25161)
  - The shipping line's amount is sourced from the cart's own shipping total rather than being gated on constructing a `Carrier` object. Carts with no resolvable carrier previously dropped the shipping amount from the Two payload entirely - the order's totals no longer reconciled with the shop's - and now reconcile
  - The shipping VAT rate is the platform-declared rate resolved from the cart's carrier list, never derived from `tax / net`, consistent with the line-item rate relay (TWO-24880)
  - When the selected delivery option spans several tax-rules groups, the shipping charge is split into one line per declared rate instead of emitting a single blended rate
- **The shipping VAT-rate lookup no longer assumes any step of the cart's carrier chain succeeds** (TWO-25180)
  - `Cart::getDeliveryOption()` and `Cart::getDeliveryOptionList()` can raise rather than return: both build the package list, construct `Address`/`Country`/`Carrier` objects (whose constructor and entity mapper read the database and throw), read cart rules over the database, and - for module-priced shipping - call into third-party carrier module code. A raise surfaced as an HTTP 500 on the checkout page, bypassing both the fallback and the deliberate loud refusal. It is now treated as what it is, an unreadable delivery option, and takes the same documented fallback: the tax-rules group declared on the cart's own carrier, which is a merchant declaration rather than an inference
  - A carrier whose declared tax-rules group cannot be read (the group is a database lookup, and the rate resolver constructs an address) now refuses the order naming the carrier and the cause, instead of raising a 500 or falling through to a silent 0% shipping VAT
  - An empty carrier list on the selected delivery option, and a delivery-option key core auto-selected as `null`, are both handled explicitly rather than relying on which levels of the array happened to be falsy
  - The refusal message no longer claims the no-available-carrier sentinel (`carrier_list = [0 => 0]`) for conditions that are not it - each refusal now names its own cause
  - No rate is ever invented to fill the gap: PrestaShop has no shop-level shipping tax-rules group (only ecotax and gift wrapping have one; shipping's lives per carrier), so where no declared group is reachable the correct fallback is the loud refusal
  - The spec now drives delivery-option states PrestaShop core actually produces - verified against core sources for 1.7.6.0, 8.1.7 and 9.0.0 - including the unloaded `Carrier(0)` instance the no-available-carrier sentinel really arrives as, replacing a fixture shape core never returns
- **Checkout-path latency: 12 buyer-blocking round trips reduced to 5** (TWO-24799)
  - Company lookups that Two cannot resolve are now negatively cached (short TTL, keyed on org number + country + address ID), so a saved address carrying an unresolvable org number no longer re-pays the verification round trip on every checkout update
  - Order-intent calls are deduped against a session-scoped decision snapshot, so a checkout update that moves no decision input reuses the previous decision instead of re-running the intent call
  - Measured over a six-step checkout session

### Changed
- **Buyer-facing copy: the approved order-intent notice now says checks continue** (TWO-25213, umbrella TWO-24739)
  - `Your invoice with Two is likely to be accepted for %s` becomes `Your invoice with Two is likely to be accepted for %s, subject to additional checks.`, and the no-company variant gains the same trailing caveat
  - This lands all four plugins on one canonical pair of strings; the other platforms already carried the caveat. Declined copy is unchanged. Translators see the two changed source strings as new
- **Invoice upload is now gated on the merchant record, not an admin toggle** (TWO-25111, per decision TWO-25106 Option A)
  - The plugin-side invoice upload (own-invoice merchants) now triggers only when the `invoice_distributed_by_merchant` flag on the Two merchant record (`GET /v1/merchant`) is true - the same signal checkout-api itself enforces (TWO-24761), and the same gating model Magento (TWO-24758) and WooCommerce (TWO-24757) use
  - The flag is cached by the existing TTL-gated merchant-record fetch (shared with `available_terms`/`due_in_days`); absent-from-response is treated as false, a failed fetch serves the last known value, and a merchant identity change fails closed
  - Module version bumped to `2.6.0`

- **Line-item VAT rates are now relayed from PrestaShop's own tax configuration** (TWO-24880)
  - Product, ecotax, shipping and gift-wrapping lines source their tax rate from the merchant's configured tax rules group, resolved at the cart's tax address (`PS_TAX_ADDRESS_TYPE` granularity) - the same resolution PrestaShop uses to compute the amounts. The rate is never derived from `tax / net`, never snapped toward nearby rates, and never substituted with a fallback
  - Removed the hardcoded Spanish 21% fallback and the snap-to-known-contexts machinery entirely - a snap-to-canonical step could relabel a reduced-rate line (e.g. 10%) to a neighbouring rate (e.g. 21%) while still passing amount checks, producing an incorrect VAT breakdown on the invoice
  - Divergence now fails loud: when the declared rate does not reconcile with PrestaShop's applied amounts (beyond a 2-cent rounding tolerance), order building throws instead of silently correcting - the merchant sees the actionable cause in the shop log, the buyer sees a controlled decline
  - Discount lines keep the exact-cent canonical-rate split; unattributable discounts now fail loud instead of emitting a blended synthetic rate
  - `PS_ATCP_SHIPWRAP` (average-tax shipping/wrapping) carts split the charge across the cart's canonical product rate classes instead of ever emitting the blended average rate
  - Free-shipping discount lines mirror the shipping line's emitted rate, and the net-cap path now keeps gross/net/tax rate-consistent
  - Tax rate precision raised to PrestaShop-native 6dp (rates still capped at 2 decimals of percent per e-invoicing rules); per-line validation now validates the emitted amounts and also asserts `gross == net + tax` exactly

### Removed
- **Dead "Activate company org.id auto-complete" admin toggle removed** (TWO-25190, umbrella TWO-24739)
  - `PS_TWO_ENABLE_COMPANY_ID` was a rendered switch on the advanced-settings page whose only consumer was the `company_id_search` entry in the `Media::addJsDef()` `twopayment` payload - and no JavaScript ever read that entry. Checkout company lookup is driven solely by `company_name_search` (`views/js/twopayment.js`), which is untouched, so the switch advertised a setting that did nothing whichever way a merchant set it
  - The 2.6.5 upgrade script deletes the stored row on existing installs (install seeded it to `1`), and uninstall clears it for shops that never ran the upgrade
  - Module version bumped to `2.6.5`

- **Dead `PS_TWO_ENABLE_B2B_B2C` configuration key removed** (TWO-24739)
  - The key was never a rendered admin field and was never read for a behavioural decision. Its only two references were the advanced-settings form's value hydration and a matching `Configuration::updateValue()` in the save handler, so saving advanced settings wrote a blank value into the row on every submit while nothing ever consulted it. Two is B2B-only - there is no B2B/B2C mode to switch
  - The 2.6.4 upgrade script deletes the stored row on existing installs (any shop whose merchant has ever saved advanced settings holds one), and uninstall clears it for shops that never ran the upgrade
  - Module version bumped to `2.6.4`

- **`PS_TWO_ENABLE_SOLE_TRADER` admin setting retired - sole trader is gated on country only** (TWO-25166, umbrella TWO-25163)
  - The "Enable sole trader checkout" switch is removed from the module configuration page. Whether a buyer can check out as a sole trader is Two's registry answer for their billing country (`GET /registry/v1/supported-company-types/<ISO>`, TWO-24753 - the UK and US currently), never a merchant preference; this matches Magento's toggle-less behaviour, the cross-plugin target state
  - `TwoSoleTrader::isEnabled()` is gone and `isAvailable()` now consults the registry alone. The registry country check was already the barrier the order-intent controller enforced before minting delegated-authority tokens, so removing the toggle does not widen access to that endpoint - the country gate is unchanged and still fails closed on a cart with no invoice address
  - Both PrestaShop staging shops had the feature invisible because `install()` and the 2.6.1 upgrade both wrote an explicit `0` default; the 2.6.3 upgrade script deletes the stored row, and uninstall still clears it for shops that never ran the upgrade
  - Module version bumped to `2.6.3`

- **`PS_TWO_USE_OWN_INVOICES` admin setting retired** (TWO-25111)
  - The "Upload Own Invoices to Two" switch is removed from the module configuration page; the upgrade script deletes the configuration row and any leftover value has zero effect (covered by unit test)
  - Merchants who had the toggle enabled were server-side whitelisted, and all previously-whitelisted merchants already carry `invoice_distributed_by_merchant = true` (TWO-24761), so no merchant loses the feature on upgrade

### Added
- **Real-engine integration probes, gated on every pull request** (TWO-25217)
  - `tests/integration/` had documented a required real-engine matrix without any of it running. It now holds executable probes, discovered and run inside a real PrestaShop container by `dev/ci/run-integration-probes.sh` from a new `integration.yml` workflow on PrestaShop 8 and 9. Hermetic like every other job here: no browser, no network, no Two credentials
  - First probe covers the **Default shipping tax code** (TWO-25200) end to end: it asks the module for the real order-intent payload of a carrier-less cart and asserts the `SHIPPING_FEE` line's gross, net, tax, rate and class name, plus the log severity, across all four states (unset, group declared, core's "No tax" sentinel, since-deleted group)
  - This closes a gap the offline suite cannot: `tests/DefaultShippingTaxCodeSpec.php` proves the decision logic against a hand-rolled core stub, so it assumes a cart shape rather than observing one. Verified on 8.2.7, that shape does not arise from a broken carrier setup at all - core discards the whole delivery-option list on its no-carrier sentinel and the cart's shipping total derives from that same list, so a coverage gap yields shipping of `0.00` and exercises nothing. It takes a module that *injects* a carrier-less delivery option, which now ships as a test fixture (`tests/integration/fixtures/twocarrierlesstest`, inert until armed)
  - `make carrierless-shop` stands the same shape up on the local dev shop and reveals the hidden admin field; `make test-integration` runs the probes against it; `make carrierless-off` reverses it. Every record is created through ObjectModel rather than SQL
- **Optional "Default shipping tax code" for shops that price shipping outside the carrier table** (TWO-25200)
  - PrestaShop keeps the shipping VAT declaration on the carrier row (`carrier_tax_rules_group_shop`) and nowhere else. A merchant running custom logistics with `id_carrier = 0` has no carrier to declare it on - core discards the whole delivery-option list on that sentinel - so the plugin refused the order rather than guess a rate (TWO-25161 / TWO-25180)
  - A new Advanced Settings field lets such a merchant declare that group on the module instead. Resolution order is now: the carrier's declared tax-rules group, then this default, then the same loud refusal as before. The default is never consulted when a carrier does declare a group
  - **No default value.** An install that has not set it behaves exactly as before, and a selection that points at a since-deleted group is treated as unset rather than relayed as 0%
  - The field is **hidden unless the install opts in** with `define('_TWO_ENABLE_DEFAULT_SHIPPING_TAX_CODE_', true);` in `config/defines_custom.inc.php` (see README). The constant gates the admin field only - a value the merchant already saved keeps working if the constant later disappears - and a save while the field is hidden never rewrites the stored selection
  - Whenever the fallback is actually used, the shop log carries a warning naming the group, its id and the resolved rate, so a merchant on the fallback path is distinguishable from one resolving normally
  - Module version bumped to `2.6.8`
- **Real FX layer on Two's own spot rates** (TWO-25105)
  - Every Two-side currency conversion (platform/merchant minimum-order gate, minimum-order decline hint, admin floor display and save validation, fixed-surcharge/cap re-denomination) now uses the rates from `GET /refdata/v1/fx-rates` - the same EUR-pivot table checkout-api enforces server-side - instead of PrestaShop core's own conversion rates
  - The full rate table is fetched server-side with the merchant API key (never from browser JS), cached in module configuration with a 6h TTL refreshed from the checkout media hook, and fetched on demand when a not-yet-cached currency reaches a conversion; the response's `as_of` staleness floor is retained alongside the rates
  - Failure posture: a failed refresh serves the last-known-good table and retries after a short backoff; gate conversions fail closed only when no table was ever fetched, display conversions fail soft
  - Fixed surcharge amounts and caps (configured in the shop default currency) are converted into the quote currency before the pricing call, replacing the previous single-currency pinning; an unconvertible figure omits the fee quote instead of sending a wrong-currency amount
- **Sole trader checkout** (TWO-24755, WooCommerce/Magento parity)
  - The payment step shows a Business / Sole trader toggle for buyers in countries where Two's registry supports sole traders (`GET /registry/v1/supported-company-types/<ISO>`, currently the UK and US). That country answer is the only gate - there is no merchant admin toggle (TWO-25166)
  - Choosing Sole Trader mints delegation + autofill tokens server-side with the merchant API key and opens Two's hosted signup popup; on completion the buyer's company data autofills from `GET /autofill/v1/buyer/current` (case-insensitive email match) and persists through the existing company-save path, so order-intent and payment run unchanged
  - Module version bumped to `2.6.1`
- **Buyer surcharge shown as a real PrestaShop cart line** (TWO-24739 parity)
  - Selecting Two at checkout now adds the payment-terms fee as a hidden virtual product line, so the fee appears in PrestaShop's own order summary, cart, order and invoice totals - previously it existed only on the Two-side invoice
  - The line's net amount comes from the same live fee quote as the Two order payload (single computation path); its tax applies the merchant-selected tax rules group (see TWO-25071 below)
  - Selecting any other payment method removes the line; add/remove is idempotent against re-clicks, reloads and resumed carts (front-controller stale-line guard)
  - Order creation self-heals the line server-side and fails closed if the cart's fee and the Two payload's fee ever diverge beyond rounding tolerance
- **Graceful invoice retrieval when order is not yet fulfilled** (TWO-25042, part of TWO-25040)
  - Invoice PDF downloads (customer order page and admin order view) are now routed through module controllers instead of linking the browser directly to the payment API
  - On `400 ORDER_NOT_FULFILLED` the module checks the order state: `FULFILLING` shows an informational "not ready yet" notice, `FULFILLED` retries the fetch once, and any other state is reported with the state named
  - Customer downloads are protected by the same secure-key ownership guard as the cancel/confirmation callbacks (guest checkout included); admin downloads go through a permission-gated admin controller

### Changed
- **Removed the Personal/Business/Sole-trader account-type selector on the address form** (TWO-24755 rework)
  - The address form is now plain B2B: the company field is always present, with no `PS_TWO_USE_ACCOUNT_TYPE`-gated selector - matches the Magento and WooCommerce plugins' current structure
  - The order-intent security gate no longer checks an account type; a company name plus a verified organization number is the business guard (registered businesses and enrolled sole traders both arrive with that pair)
  - The upgrade script removes the now-unused `PS_TWO_USE_ACCOUNT_TYPE` configuration on existing installs (no live merchants are on this plugin yet)
- **Surcharge tax now uses the merchant's own tax rules group** (TWO-25071)
  - The flat "Surcharge Tax Rate (%)" field is replaced by a "Surcharge Tax Rules Group" dropdown - the same tax rules groups assigned to products; an unsaved config pre-selects "No tax" so taxing the fee is always an explicit merchant choice
  - A deactivated tax rules group that is still the configured selection stays in the dropdown (flagged "(inactive)") so an unrelated settings save can never silently reset the surcharge tax to "No tax"
  - Module version bumped to `2.5.0`; the upgrade script does NOT auto-convert a previously configured flat rate (inventing a tax rules group from a bare percentage is destination-blind) - instead shops that had the flat rate set and no group selected get a logged warning and a persistent module-config notice ("surcharge tax needs re-selection", surcharge untaxed until saved)
  - The hidden fee product carries the selected group on its `id_tax_rules_group` like any real product, so PrestaShop's native tax engine applies per-country/state rules, combined multi-rate stacking, and destination-based zero-rating (no rule for the destination = untaxed) to the fee line
  - The tax rate reported to Two's order API is resolved through the same core machinery (`TaxManagerFactory`) for the cart's tax address, with the same shop-wide gates (taxes disabled, VAT-number B2B exemption), so the PrestaShop line and the Two payload cannot drift and the order-create parity gate holds on cross-border orders
  - Selecting "No tax" (PrestaShop's built-in id-0 group) keeps the fee untaxed for every destination; an unset/invalid selection fails safe to "No tax"
  - Removed: the module-managed synthetic Tax/TaxRulesGroup/TaxRule graph, its `PS_TWO_SURCHARGE_TAX_SETUP` tracking blob, self-heal/advisory-lock machinery and uninstall cleanup (never released; uninstall still deletes the legacy configuration rows). Uninstall never deletes the merchant's own tax rules group

## Latest Release: v2.4.0

**Release Date:** 2026-02-25

**Highlights:**
- Cart snapshot guard to block local order creation if cart changes after Two order creation
- Idempotency key header on `/v1/order` creation to prevent duplicate provider orders on retries
- Added attempt metadata columns for snapshot hash and order-create idempotency key

**Upgrade:** Includes database migration creating/updating `twopayment_attempt`.

## [2.4.0] - 2026-02-25

### Added
- **Checkout Attempt Persistence**: New `twopayment_attempt` table tracks provider-first checkout attempts
  - Stores attempt token, cart/customer linkage, Two order metadata, and lifecycle status
  - Supports idempotent callback handling and safe retries
- **Cart Snapshot Consistency Check**: Callback finalization validates cart still matches original checkout payload hash
  - If cart drift is detected, local order creation is blocked
  - Provider order is cancelled (best effort) and customer is sent back to checkout
- **Order Create Idempotency Header**: `/v1/order` calls include `X-Idempotency-Key`
  - Key is derived from cart/customer/environment and normalized snapshot hash
  - Reduces duplicate provider orders when requests are retried
- **Attempt Metadata Columns**:
  - `cart_snapshot_hash`
  - `order_create_idempotency_key`

### Changed
- **Security hardening for callback and template surfaces**:
  - Legacy `id_order` confirmation/cancel front-controller paths now require secure callback authorization (query `key` or matching logged-in customer secure key) before any order-state mutation.
  - Buyer/admin/payment-return templates now escape dynamic Two/order fields before rendering links and text.
  - Production environment now enforces TLS verification even if the optional SSL-disable flag is set.
- **Order intent and callback hardening (provider-first parity)**:
  - Authoritative payment-submit order intent now fail-closes on strict reconciliation drift before provider `/v1/order_intent` call.
  - Callback-time local order creation now wraps `validateOrder()` with race-safe recovery using existing order-by-cart lookup.
  - Provider lifecycle cleanup now performs best-effort cancel on terminal post-create failures (including missing `payment_url`) with explicit lifecycle logs.
- **Order intent i18n normalization**:
  - Replaced remaining hardcoded order-intent user-facing errors with translation-surface strings.
  - Added Spanish (`es`) translations for the normalized order-intent error keys.
- **Coverage and validation documentation updates**:
  - Added test coverage for strict payment-submit drift blocking, callback race recovery, and provider cancel helper behavior.
  - Added real-engine integration matrix requirements for PrestaShop `1.7.8`, `8.x`, and `9.x` under `tests/integration/README.md`.
- **Order intent/company-search client auth safety + intent address parity**:
  - Added client-side request guards on frontend public Two API calls (`/v1/order_intent`, company search/detail endpoints) to block accidental auth header propagation.
  - Order intent payload now includes both `billing_address` and `shipping_address` for parity with order create/update payload composition.
- **Discount tax-rate canonicalization hardening**:
  - Discount-line fallback tax-rate derivation now snaps near-context drift to canonical cart tax contexts (for example `0.212` -> `0.21`), preventing provider-side strict VAT rate rejections on ES orders.
- **Shipping tax-rate canonicalization hardening**:
  - Shipping-line tax rate now snaps to canonical cart/carrier tax contexts when drift is only rounding noise (for example `0.211` -> `0.21`), preventing provider-side strict ES VAT rejections.
- **Additional tax-rate drift hardening (wrapping + product fallback)**:
  - Gift-wrapping tax-rate derivation now snaps to canonical cart tax contexts when drift is rounding-only.
  - Product-line fallback tax-rate derivation now reuses configured product tax-rate contexts to avoid minor synthetic drift when a line is missing/loses its direct rate field.
- **ES strict fallback default for unresolved line rates**:
  - Added an ES-only canonical normalization pass across built line items.
  - When a line tax-rate remains unresolved but formula-safe with canonical fallback, the fallback defaults to `0.21`.
- **Buyer confirmation payment-term clarity**:
  - Post-order buyer success card now renders invoice terms with explicit term type: `Standard + X days` or `End of Month + X days`.
- **Tax precision hardening for payload formulas**:
  - Line-item `tax_rate` serialization now preserves non-integer VAT rates (for example `0.055` for 5.5%) to keep `tax_amount = net_amount * tax_rate` consistent.
  - Tax subtotal grouping precision remains compatibility-safe while checkout snapshot tax-rate normalization remains stable at two decimals.
- **Cart-rule-aligned discount attribution**:
  - Discount line generation now prefers PrestaShop cart-rule monetary fields (`value_real`, `value_tax_exc`) to keep per-rule discount lines aligned with invoice semantics.
  - Weighted tax-context allocation remains as fallback when rule-level monetary metadata is unavailable.
  - Mixed cart-rule metadata handling now preserves complete rule rows and falls back only for unresolved remainder, with unresolved free-shipping remainder carved out on shipping VAT context.
- **Currency compatibility guardrails**:
  - Added explicit cart-currency compatibility checks in `hookPaymentOptions()` following PrestaShop payment-module patterns.
  - Added server-side currency guard in payment submit controller to fail fast before provider calls when currency is unsupported.
  - Added explicit ISO allowlist coverage in module checks for `NOK`, `GBP`, `SEK`, `USD`, `DKK`, and `EUR` (all fully supported).
- **Checkout address-basis consistency**:
  - Order intent backend now prioritizes invoice/billing address identity and keeps delivery only as fallback for backward compatibility.
  - Frontend order intent payload now sends both invoice and delivery address identifiers to keep mixed-theme flows compatible.
- **Idempotency and callback safety**:
  - Order-create idempotency key no longer depends on a time bucket for identical cart snapshots.
  - Added callback-time rebinding guard to prevent overwriting an existing local order binding with a different Two order ID.
- **Provider-First Checkout Flow**: Payment controller now creates Two orders before local PrestaShop orders
  - Eliminates local order creation/deletion cycle on provider rejection
  - Prevents rejected attempts from producing local order side effects
- **Unified Checkout Company Resolver**:
  - Payment controller now uses shared module fallback logic for company/org-number extraction
  - Applies country-aware cookie validation and multi-field org-number extraction consistently at checkout
- **merchant_order_id Alignment**: After callback-time local order creation, module performs best-effort Two order update to set `merchant_order_id` to the real PrestaShop `id_order`
- **Callback Orchestration**:
  - Confirmation controller now supports `attempt_token` callback flow and creates local order only after verified provider state
  - Cancel controller now supports `attempt_token` cancellation without creating local orders
  - Both controllers keep legacy `id_order` paths for backward compatibility
- **Two cancellation/verification consistency hardening**:
  - Buyer portal URL resolution now uses explicit buyer domains by environment (`buyer.two.inc` for production and `buyer.sandbox.two.inc` for non-production), with a safe sandbox fallback for unknown environments.
  - Checkout callback handling now treats canceled attempts as terminal during confirmation, and cancel flow resolves local order linkage via cart fallback to avoid race-driven state mismatches between Two (`CANCELLED`) and PrestaShop.
  - Local order-state sync now force-maps provider `CANCELLED` to the configured PrestaShop cancellation status during confirmation handling and admin provider-sync refresh.
  - Legacy cancel callback no longer sets local cancelled state unless provider order fetch confirms `CANCELLED`, preventing transient local cancel entries when provider cancellation did not complete.
  - Legacy cancel callback now fail-closes when stored `two_order_id` mapping is missing, preventing local cancellation without a verifiable provider order link.
  - Fulfillment status updates now block/revert when the provider order is `CANCELLED` (using stored and fresh provider state checks), with explicit logs to prevent shipping progression on non-fulfillable Two orders.
  - Back-office fulfillment blocking now also surfaces an on-screen warning in the admin controller when a cancelled Two order is reverted to cancelled status.
  - Added `actionObjectOrderHistoryAddBefore` guard to rewrite pending `Verified` and fulfillment-trigger history inserts to the configured cancelled status when the provider order or attempt is terminally `CANCELLED`, preventing visible status flip-flops in order history.
  - Late confirmation race handling now blocks post-cancel status rewrites (`CONFIRMED`/`FAILED`) so a buyer-backed-out checkout remains cancelled.
- **Tax Payload Accuracy Hardening**:
  - Tax rates are now serialized to fixed 2 decimal places (`tax_rate` like `0.21`) across line items, tax subtotals, and checkout snapshots
  - Product tax rate selection now prioritizes applied PrestaShop amounts when configured and applied rates diverge
  - Top-level `tax_rate` is omitted from `/v1/order` and `/v1/order_intent` request payloads
  - `tax_subtotals` is optional and omitted entirely when `PS_TWO_ENABLE_TAX_SUBTOTALS` is disabled
  - Added back-office setting `PS_TWO_ENABLE_TAX_SUBTOTALS` in "Other Settings" to control whether `tax_subtotals` is sent
- **Provider Error Handling Hardening**:
  - `getTwoErrorMessage()` now treats HTTP `>= 400` as an error even when provider body is empty/non-JSON
  - Nested `data.error_message`/`data.message` responses are now parsed consistently
- **Session Company Country Safety**:
  - Legacy company cookies without `two_company_country` are now cleared when validating against a known address country
  - Prevents stale cross-country company/org-number reuse in mixed-country checkouts
- **Business Account Gate Strictness**:
  - When account-type mode is enabled, checkout now requires explicit `account_type=business` for Two visibility and order-intent approval.
  - Missing `account_type` no longer auto-falls back to company/org-number inference in strict mode.
- **Order Intent Enforcement**:
  - Removed the admin toggle for order intent pre-approval from "Other Settings"
  - Enforced order intent as mandatory for Two checkout server-side validation
  - Updated checkout initialization to always run order intent pre-check logic
- **Checkout Compatibility Hardening**:
  - Reworked `CustomerAddressFormatter` override to delegate to core formatter and apply only minimal Two-specific field adjustments
  - Removed remote CDN jQuery fallback from front-controller media hook
  - Added same-origin runtime jQuery fallback loader in frontend module bootstrap for legacy environments
- **Address Switching Reliability**:
  - Prevented stale same-country session company reuse when the shopper switches to a different checkout address/company
  - Added address-aware session marker (`two_company_address_id`) for company-cookie synchronization
  - Reset order-intent UI/server state and re-enable Two payment option after checkout address updates
  - Cleared stale hidden `companyid` values when company input changes to avoid cross-address mismatch blocking
- **Checkout Step Stability**:
  - Restricted order-intent submit interception to payment confirmation forms/buttons only (no blocking on personal-info or address step continue actions)
  - Removed fallback Two-selection detection based on generic form action matching to avoid false positives outside payment step
- **Organization Number Parsing**:
  - VAT extraction now strips prefix only when it matches the current address country ISO (prevents truncating valid org numbers like `SC806781` for GB)
- **Order Intent Company Context**:
  - Bound checkout approval message company name to backend order-intent payload company data
  - Cleared stale `lastCompany` state on order-intent reset to prevent cross-address message leakage
- **Address Selector Accuracy**:
  - Order-intent and company-cookie flows now read the selected (`:checked`) checkout address ID instead of the first address input in DOM
  - Order-intent server resolver now uses selected delivery/invoice address context consistently for country/company resolution
- **Two Payload Parity Hardening (Phase 1)**:
  - Intent/create/update payloads now share one server-side line-item builder and bottom-up amount derivation
  - Shipping is represented as explicit `SHIPPING_FEE` line and cart discounts as explicit negative line items
  - Added fail-closed order/cart reconciliation gate before outbound order payloads when totals drift beyond tolerance
- **Order Intent Auth Boundary**:
  - Added endpoint-aware header policy so `/v1/order_intent` never includes `X-API-Key`
  - Server-to-server Two endpoints keep API-key authentication on backend requests
- **Payment Submit Authorization Hardening**:
  - `/payment` now performs a fresh backend `/v1/order_intent` check and treats frontend intent cookies as telemetry only
  - Checkout submit token validation is enforced before provider calls in payment submission
- **Callback Amount Integrity**:
  - Callback-time `validateOrder()` now uses provider `gross_amount` from Two order response
  - Local order creation is blocked when provider amount is missing/invalid

### Fixed
- **Gift wrapping parity**:
  - Added explicit gift wrapping line-item construction so wrapping totals are represented in Two payloads and reconcile with PrestaShop grand totals.
- **Order intent payload regression on rounded mixed discounts**:
  - Discount line-item tax rate now uses higher precision when derived from rounded net/tax splits to preserve `tax_amount = net_amount * tax_rate` validation in large cart-rule discount scenarios (including free-shipping combinations).
- **Cart-rule discount VAT context compliance**:
  - Cart-rule discount rows now split into canonical tax-rate segments when needed, avoiding blended synthetic VAT rates while preserving per-rule net/gross totals.
  - Improves provider compatibility on strict VAT validation paths for mixed discount baskets.
- **Fallback free-shipping attribution hardening**:
  - When cart-rule monetary metadata is incomplete, fallback discount logic now attributes free-shipping discounts to the shipping VAT context first.
  - Reduces blended shipping/product discount attribution drift on mixed-tax baskets in fallback mode.
- **Order intent account-type strict enforcement**:
  - In account-type mode, order intent now blocks missing/non-business account types instead of treating missing values as business.
- **Ecotax explicit line modeling**:
  - Product lines now split ecotax into a dedicated `SERVICE` line when safe ecotax totals are present, preserving formula integrity and explicit tax context.
- **Payment term cookie warnings in tests/runtime**:
  - Guarded cookie reads in `getSelectedPaymentTerm()` to avoid undefined property warnings.
- **Buyer metadata warning suppression**:
  - `buyer_department` and `buyer_project` payload fields are now read with property checks to avoid undefined property warnings on default address entities.
- **Checkout Address Formatter Stability**:
  - Fixed `CustomerAddressFormatter` override constructor to call `parent::__construct(...)`
  - Prevents `Call to a member function trans() on null` fatals on `/order` during checkout address step rendering
  - Preserves Two-specific field adjustments while keeping core formatter translator initialization intact
- **Checkout Address Field Order**:
  - Restored country selector positioning immediately before company field in checkout addresses
  - Keeps core field metadata/validation intact by reordering existing formatter output instead of rebuilding fields
- **Address Identification Number Guard**:
  - Added frontend guard on checkout address submit to prevent backend failures when country requires identification number and `dni` is empty
  - Auto-fills `dni` from `companyid`/`vat_number` when available before submit
- **Checkout Country Switch (UK → ES) 500 Regression**:
  - Fixed `CustomerAddressFormatter` override `setCountry()/getCountry()` to delegate to core formatter state
  - Prevents stale country format when shopper switches address country during checkout (e.g. UK to Spain)
  - Ensures ES-required `dni` validation is applied before persistence, avoiding `Property Address->dni is empty` fatals
- **Order Intent Reconciliation False Negatives**:
  - Increased order/cart reconciliation tolerance to `0.02` to match real PrestaShop cent-level rounding drift
  - Reconciliation drift is now warning-level by default and does not block order-intent precheck payloads
  - Reconciliation threshold checks now compare integer cents to avoid float precision boundary rejects at exactly `0.02`
- **Provider-First Reconciliation Handling**:
  - Intent payload builder continues when cart reconciliation drift is detected
  - Create/update payload builders only hard-block on material mismatches (> `1.00`) to guard true parity errors
  - Module logs drift details for observability while avoiding local false-negative blocks from cent-level artifacts
- **Presta-Native Amount Modeling**:
  - Product and shipping line monetary fields now keep PrestaShop net/tax/gross totals as canonical values
  - Discount totals are split across detected tax contexts instead of a single blended synthetic discount line
  - Preserves line-level formula compliance while better matching PrestaShop rounding behavior
- **Discount Rule Description Warning**:
  - Guarded optional `value` key access in cart-rule description builder to avoid PHP warnings on stores where cart-rule payload omits that key

### Technical
- Added upgrade script `upgrade-2.4.0.php`
- Module version bumped to `2.4.0`
- `twopayment_attempt` schema includes snapshot and idempotency metadata
- Added strict line-item formula validation gate before building intent/create/update payloads
- Added back-office media hook implementation for module/admin order styling consistency
- Fixed settings persistence path: `PS_TWO_DISABLE_SSL_VERIFY` now saves through "Other Settings" handler (where field is rendered)
- Added test harness and automated checks:
  - Offline deterministic test runner (`php tests/run.php`)
  - PHPUnit-compatible test suite scaffolding (`tests/OrderBuilderTest.php`, `phpunit.xml.dist`)
  - GitHub Actions workflow for push/PR test execution
  - CI syntax checks now include core module/controller files in addition to test files
  - Added coverage for HTTP-only provider failures, legacy session company country edge cases, shared checkout company resolver behavior, admin media hook routing, account-type fallback gating, and SSL setting persistence paths

---
## [2.3.2] - 2026-01-22

### Added
- **Invoice Upload Feature**: Re-enabled the invoice upload functionality
  - When enabled, PrestaShop-generated PDF invoices are uploaded to Two when orders are fulfilled
  - Merchants can customize their invoice templates to include Two's payment details
  - PrestaShop invoice templates can be modified in `/themes/[theme]/pdf/invoice.tpl` or `/pdf/invoice.tpl`
  - Feature remains disabled by default - must be coordinated with Two before enabling

### Changed
- **Invoice Upload Configuration**: Re-enabled `PS_TWO_USE_OWN_INVOICES` toggle in admin settings
  - Clear instructions explaining merchants must customize their invoice template
  - Example Smarty code provided for adding Two-specific content only to Two orders
  - Shows how to use `{if $order->module == 'twopayment'}` conditional
  - Warning to contact Two support before enabling

### Technical
- No database schema changes required
- No new hooks required
- Invoice upload uses existing Three-step process (request URL, upload to cloud storage, poll status)

---

## [2.3.1] - 2026-01-22

### Added
- **Plugin Information Tab**: New admin tab displaying plugin capabilities, limitations, and troubleshooting tips
  - Clear list of what the plugin can and cannot do
  - Important requirements for customers (company name, phone, etc.)
  - Common troubleshooting tips with solutions
  - Support contact information and version display

### Fixed
- **Tax Amount Calculation**: Fixed "Line item tax amount differs from tax rate * net amount" API errors
  - Tax amount now calculated using Two's required formula: `tax_amount = net_amount * tax_rate`
  - Ensures mathematical consistency between tax_rate, net_amount, and tax_amount
  - Resolves API rejection for orders with rounding discrepancies
- **Shipping Cost with Free Shipping**: Fixed shipping detection when free shipping cart rules are active
  - Now uses `getPackageShippingCost()` to get carrier cost before cart rules are applied
  - Shipping line item now includes correct amount even with free shipping promotions
- **Tax Rate Sourcing**: Improved tax rate determination using PrestaShop's native `rate` field
  - Primary source: PrestaShop's configured tax rate (canonical value)
  - Fallback: Calculate from amounts when rate field is unavailable
  - Edge case handling for tax-exempt customers and rate field inconsistencies

### Changed
- **Tax Calculation Logic**: Tax amounts are now calculated from rates instead of taken from PrestaShop
  - Guarantees Two API formula compliance: `tax_amount = net_amount * tax_rate`
  - Gross amount validation with configurable tolerance
  - Debug logging for rate variances when debug mode is enabled

### Technical
- No database schema changes required
- Backward compatible with all existing configurations
- PHP 7.1+ compatible

## [2.3.0] - 2025-11-21

### Added
- **End-of-Month (EOM) Payment Terms**: New payment term type for B2B invoicing
  - Supports EOM+30, EOM+45, and EOM+60 day terms
  - Payment calculated from end of current month at fulfillment, plus selected days
  - Example: Order fulfilled Jan 15 with EOM+30 = Payment due Feb 28 (end of Jan + 30 days)
- **Payment Term Type Configuration**: Radio button selection in admin (Standard vs EOM)
  - Dynamic UI: EOM mode shows only 30/45/60 day options
  - Standard mode shows all available terms (7/15/20/30/45/60/90 days)
  - Clear explanations with real-world examples for each type
- **API Integration**: `duration_days_calculated_from: "END_OF_MONTH"` field added to order payload for EOM terms
- **Database Schema**: Added `two_payment_term_type` column to store term type per order
- **Enhanced Buyer Display**: 
  - Standard terms: "Pay in 30 days" (multilingual)
  - EOM terms: "Pay in 30 days from end of month" (clear, localized)
  - Dynamic description text changes based on term type
- **Admin Order View**: Shows "End of Month + 30 days" with EOM badge for clarity
- **Upgrade Script**: `upgrade-2.3.0.php` with backward-compatible defaults
- **Debug Mode**: Admin toggle for detailed diagnostic logging (Other Settings → Enable Debug Mode)
  - Logs tax calculations, rate fields, and gross/net amounts per product
  - Only enable when requested by Two support for troubleshooting
- **Phone Number Fallback**: Automatic fallback from `phone` to `phone_mobile` field
  - Handles cases where customers only provide mobile number
  - Graceful handling when no phone provided (Two API validates)

### Changed
- **Checkout Display**: Smart label/unit hiding for EOM terms (no verbose "Pay in EOM+30 days")
- **Payment Terms Selector**: Term format changes based on type (tooltips explain EOM)
- **API Payload Builder**: New `buildTermsPayload()` method conditionally adds EOM field
- **Available Terms Logic**: `getAvailablePaymentTerms()` filters based on term type
- **Tax Rate Calculation**: Now validates tax rate from actual amounts (gross - net / net)
  - Handles edge case where PrestaShop `rate` field is 0 but tax is applied
  - Logs anomalies when rate field doesn't match calculated rate
  - Uses calculated rate as source of truth (what customer actually pays)
- **Company Messaging**: Clearer guidance when company data is missing
  - "Go back to your billing address and enter your company name in the Company field"
  - "Go back to your billing address and search for your company name. Select your company from the results"
  - Specific status codes: `no_company`, `incomplete_company` for better UX

### Fixed
- Invoice upload feature temporarily disabled (will be re-enabled after further testing)
- **Tax Rate 0% Issue**: Fixed edge case where tax rate was sent as 0 despite tax being applied
  - Now calculates rate from PrestaShop's actual gross/net amounts
- **Phone Validation Errors**: User-friendly messages for invalid phone numbers
  - "The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country."
- **API Validation Errors**: Comprehensive parsing of Two API validation errors
  - Phone, email, address, and company validation errors now show user-friendly messages
  - Generic fallback for unknown validation errors

### Technical
- **PHP 7.1+ Compatible**: No spread operators, arrow functions, or typed properties
- **Backward Compatible**: Existing merchants default to STANDARD type
- **Historical Orders**: Upgrade script marks existing orders as STANDARD
- **ES5 JavaScript**: Uses `function()` syntax instead of arrow functions in loops
- **Security**: Whitelist validation for term type (only STANDARD or EOM accepted)

### User Experience
- Clear admin explanations with fulfillment date examples
- Language-friendly checkout display (works in ES, EN, DE, FR, etc.)
- Tooltips on EOM term options
- EOM badge in admin order view with explanation
- Concise term display without verbose text

## [2.2.0] - 2025-11-14

### Added
- Invoice upload feature: Automatic upload of PrestaShop-generated invoices to Two's system for merchant's who want to distribute their own invoices
- Three-step upload process: Request signed URL, upload PDF to Google Cloud Storage, poll upload status
- Database schema changes: Added columns for invoice upload tracking (`two_invoice_id`, `two_invoice_upload_status`, etc.)
- Configuration option: `PS_TWO_USE_OWN_INVOICES` to enable/disable invoice upload feature
- Order status hook: `hookActionOrderStatusUpdate` triggers invoice uploads on order fulfillment
- SSL verification: Enabled by default with configurable override for corporate networks
- Constants extraction: Extracted magic numbers to named constants for better maintainability
- Comprehensive README: Added detailed documentation covering features, installation, configuration, troubleshooting
- CHANGELOG.md: Version history tracking
- **Helper functions**: Added `getOrderStatusNames()` and `buildFulfillmentStatusDescription()` for better admin UX

### Changed
- API key configuration: Fixed typo (`PS_TWO_MERACHANT_API_KEY` → `PS_TWO_MERCHANT_API_KEY`)
- Order payload building: Improved accuracy to match PrestaShop invoices exactly
- Product names: Now include attributes (e.g., "Shirt (Size: S - Color: White)") to match PrestaShop invoices
- Tax calculations: Enhanced precision handling for exact invoice matching
- Code quality: Extracted magic numbers to constants (timeouts, HTTP status codes, payment terms, etc.)
- SQL queries: Improved security using PrestaShop's `Db::delete()` method
- Error handling: Enhanced logging and user-friendly error messages
- **Payment method subtitle**: Updated default subtitle from "Receive the invoice via EHF and PDF" to "Buy now, pay later - instant credit" for better customer appeal
- **Fulfillment setting label**: Improved clarity of "Finalize purchase when order is shipped" setting
  - New label: "Automatically fulfill orders with Two"
  - Enhanced description explaining automatic fulfillment, payment terms activation, and manual alternative
- **Order Status Mapping UI**: Added visual feedback showing currently active fulfillment trigger statuses
  - Form field description now displays active statuses in green
  - Confirmation message after saving shows list of active statuses
  - Helps merchants understand which statuses will trigger fulfillment

### Fixed
- SQL injection prevention: Improved validation and casting for order IDs
- Order Intent expiry: Fixed server-side validation timing
- Invoice matching: Resolved 1-cent discrepancies between PrestaShop and Two orders
- Formula validation: Fixed "Line item net amount" and "total invoice amount" errors
- Gross amount calculations: Ensured exact matching with PrestaShop invoices
- Tax subtotals: Fixed sum validation to match order totals exactly

### Security
- SSL certificate verification enabled by default
- SQL injection prevention improvements
- Input validation enhancements
- Server-side Order Intent verification

## [2.1.2] - 2025-10-06

### Added
- Order Intent blocking: Client-side and server-side validation
- Payment terms UI: Configurable payment terms selector
- Company search: Real-time company search with Two's Company API v2
- Order Intent check: Frontend validation before payment confirmation

### Changed
- jQuery loading: Multi-layer fallback strategy for PrestaShop 1.7.6.5 compatibility
- Checkout flow: Enhanced payment option detection and event handling

### Fixed
- jQuery compatibility issues with older PrestaShop versions
- Payment option detection across different themes
- Order Intent timing and validation

## [2.1.0] - 2025-09-26

### Added
- Initial release of Two Payment module for PrestaShop
- Basic payment integration
- Order creation and management
- Admin order information display

### Changed
- N/A (initial release)

### Fixed
- N/A (initial release)

---

## Version Format

- **Major** (X.0.0): Breaking changes or major feature additions
- **Minor** (0.X.0): New features, backwards compatible
- **Patch** (0.0.X): Bug fixes, backwards compatible

## Upgrade Notes

### Upgrading to 2.3.0

1. **Backup**: Always backup your database before upgrading
2. **Automatic Migration**: Upgrade script automatically:
   - Adds `two_payment_term_type` column (VARCHAR(20), default 'STANDARD')
   - Sets `PS_TWO_PAYMENT_TERM_TYPE` configuration to 'STANDARD'
   - Updates existing orders to STANDARD type (no visible change)
3. **Backward Compatible**: Existing merchants see no changes
   - Payment terms continue to work exactly as before
   - All existing orders display correctly as standard terms
4. **New Feature**: EOM payment terms available as opt-in
   - Configure in module admin: Payment Term Type radio button
   - Only affects new orders after enabling EOM
5. **API Compatibility**: EOM requires Two backend support
   - Test on staging environment first
   - Verify `duration_days_calculated_from` field is accepted
6. **No Breaking Changes**: Standard terms unchanged, EOM is additive

### Upgrading to 2.2.0

1. **Backup**: Always backup your database before upgrading
2. **API Key Migration**: The upgrade script automatically migrates `PS_TWO_MERACHANT_API_KEY` to `PS_TWO_MERCHANT_API_KEY`
3. **Database Schema**: Upgrade script adds new columns for invoice upload tracking
4. **Configuration**: New `PS_TWO_USE_OWN_INVOICES` option added (default: disabled)
5. **SSL Verification**: SSL verification now enabled by default (configurable in module settings)
6. **Payment method subtitle**: Default subtitle updated to "Buy now, pay later - instant credit"
   - Existing installations will keep their current subtitle unless changed in admin
   - New installations will use the new default
7. **Fulfillment setting**: Label and description improved for clarity
   - Functionality unchanged, only UI text updated
   - New label: "Automatically fulfill orders with Two"
8. **Order Status Mapping**: Now shows active fulfillment statuses visually
   - Form field description displays active statuses in green
   - Confirmation message shows active statuses after saving

### Breaking Changes

None in version 2.2.0 - all changes are backwards compatible.

### Deprecations

- `PS_TWO_MERACHANT_API_KEY` (typo) - automatically migrated to `PS_TWO_MERCHANT_API_KEY`

---


For detailed technical changes, see git commit history.
