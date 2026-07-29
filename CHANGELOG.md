# Changelog

All notable changes to the Two Payment module for PrestaShop will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  - Coverage: `responseCallback` fires **exactly once** per search on all ten paths (short term, success, timeout, network error, parser error, abort, superseded, backspacing under the minimum with a request live, stale-success-outrunning-its-abort, teardown mid-search); a failure reports `unavailable` and never an empty result set; abort stays silent while a timeout does not; `degraded === true` strictness including absent-means-false and every truthy non-`true` value; the `_renderItem` patch surviving 100 re-setups, 20 country changes and 20 `updatedAddressForm` events as the same function by reference; the unavailable row being non-selectable and text-only; a company selected through the real widget landing in every field it should; the stale-selection clearing that runs on every re-render; the pre-submit identifier sync and the company-detail address fill; a destroyed instance writing nothing; and the custom no-jQuery-UI fallback's spinner and orphan-container behaviour. `tests/js/README.md` has a **Known gaps** section naming what is deliberately uncovered - the order-intent recheck and cookie persistence among it - so the list above is not read as "company search is covered"
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
