# Architectural Decisions

> **Self-updating file** - AI agents should log significant decisions here.
> Add entries at the TOP (newest first).

---

## How to Add Entries

```markdown
## [YYYY-MM-DD] Decision Title

**Context**: What problem or requirement triggered this?
**Decision**: What was decided?
**Alternatives Considered**: What else was considered?
**Rationale**: Why this approach?
**Consequences**: Trade-offs and implications
```

---

## [2026-08-10] Sole-Trader Enrolment Does Not Write Back Into The Address Form

**Context**: A completed sole-trader enrolment leaves the address form untouched (TWO-40). Adopting the enrolled identity into that form was attempted three times - first the trading name into the visible `company` field plus a publish and a cookie write as a backstop, then the same without the cookie write, then the organisation number alone mirrored into the address `dni` field - and withdrawn each time. The delivered fix is the token-mint precondition change only.

**Decision**: No write-back at all. `two:sole-trader-ready` stays a bare notification with no payload, and `TwoCompanySearch` does not listen for it.

**Alternatives Considered**:
- Name + number adoption with a publish and cookie backstop (implemented, withdrawn)
- The same without the cookie write (implemented, withdrawn)
- Organisation number alone, through the already-gated `writeOrganizationToAddressIdentifiers()` writer (implemented, withdrawn)
- A true street/postcode/city autofill from the buyer-autofill response - not possible as that endpoint is consumed today, because the response carries no address payload at all; it needs an API contract confirmation before it can be designed

**Rationale**:
- The blocker is not the write itself but what `TwoCompanySearch` already does around it. Its address-form submit handler adopts the identification field's value into the submitted organisation number, and it deliberately does not tag that adoption with a confirmed company name. Its stale-pairing check then reads company-set / number-set / tag-absent as "the buyer has edited past a stale selection" and clears the selection outright - dropping the identifier, dropping the number, and posting a company clear that destroys the session company. So any identifier an enrolment writes becomes a value the buyer's own next keystroke in the company field wipes.
- Making that safe means changing that pre-existing state machine - either stopping the submit-time adoption from taking an enrolment-written identifier, or stopping the stale check from treating a marked-but-untagged pairing as buyer-stale. That is a change to behaviour this ticket did not come to change, so it belongs to its own piece of work rather than to a guard bolted onto the new write.
- Each narrowing round produced a *new* defect class rather than converging: a country clobber through the cookie writer's DOM-guessed country, an address identifier pinned to the wrong address, a mismatched name/number pairing that made the confirmed-selection check lie, an unconditional overwrite of a number the buyer had typed themselves, and a feature detection that failed open. Three fresh sets of findings in three rounds is evidence the approach was wrong, not that the guards were incomplete.
- Nothing downstream needs it: the enrolled company already reaches the order through the session record and the selection the enrolment itself publishes, which is how the payment-step path behaves.

**Consequences**:
- The buyer still sees an empty company field and an empty identification field on the address form after enrolling. The order itself is unaffected.
- Revisiting adoption requires the submit-time adoption and stale-pairing behaviour to be settled first; a street/postcode/city autofill additionally requires the API contract to be confirmed.

---

## [2026-01-22] Consolidate AI Context into CLAUDE.md

**Context**: Had both `.cursor/rules/prestashop.mdc` and `CLAUDE.md` with overlapping content.

**Decision**: Make CLAUDE.md the single source of truth with self-improvement protocols. The Cursor rules file can be removed or minimized.

**Alternatives Considered**:
- Keep both files in sync (maintenance burden)
- Keep Cursor rules as primary (not Claude-optimized)

**Rationale**: 
- CLAUDE.md is auto-read by Claude
- Self-improvement instructions enable continuous enhancement
- Single source of truth prevents drift

**Consequences**:
- Non-Claude AI models in Cursor won't get detailed rules (acceptable)
- Must keep CLAUDE.md updated (AI can self-update)

---

## [2025-11-21] End-of-Month Payment Terms

**Context**: B2B customers requested payment terms aligned with accounting cycles.

**Decision**: Add `duration_days_calculated_from: "END_OF_MONTH"` API field with STANDARD/EOM type selection.

**Alternatives Considered**:
- Hardcode EOM calculation client-side (rejected: Two backend should own this)
- Only support standard terms (rejected: customer demand)

**Rationale**: 
- EOM common in B2B invoicing
- Backward compatible via STANDARD default
- Clean UI separation

**Consequences**:
- New database column needed
- EOM limited to 30/45/60 days (API constraint)
- Requires Two backend support

---

## [2025-11-14] Separate Invoice Upload Service

**Context**: Invoice upload logic was getting complex with signed URLs and polling.

**Decision**: Create dedicated `TwoInvoiceUploadService.php` class.

**Alternatives Considered**:
- Keep in main module file (rejected: 4000+ lines already)
- Use PrestaShop service container (rejected: version compatibility)

**Rationale**: 
- Single responsibility principle
- Easier testing and maintenance
- Clear interface

**Consequences**:
- Additional file to maintain
- Must be included/autoloaded properly

---

## [2025-10-06] Triple-Layer jQuery Fallback

**Context**: jQuery not reliably available on PrestaShop 1.7.6.x with various themes.

**Decision**: Implement three fallback layers plus JavaScript-side waiting.

**Alternatives Considered**:
- Require jQuery in theme (rejected: can't control merchant themes)
- Use vanilla JS only (rejected: massive rewrite, PrestaShop uses jQuery)
- Single CDN fallback (rejected: may still race)

**Rationale**: 
- Belt-and-suspenders approach
- Guaranteed to work regardless of theme
- Minimal overhead

**Consequences**:
- Slight initialization delay in worst case
- Extra code complexity
- Pattern must be followed in all JS

---

## [2025-09-26] Server-Side Order Intent Verification

**Context**: Client-side Order Intent check could be bypassed.

**Decision**: Re-verify Order Intent server-side before creating order.

**Alternatives Considered**:
- Trust client-side only (rejected: security risk)
- Skip client-side check (rejected: poor UX)

**Rationale**: 
- Defense in depth
- Payment security is non-negotiable
- Two API is idempotent

**Consequences**:
- Extra API call at order creation
- Cached result prevents duplicate calls
- Guaranteed no order without valid intent

---

## [2025-09-26] Modular JavaScript Architecture

**Context**: Needed maintainable checkout JavaScript across PrestaShop versions.

**Decision**: Split into focused modules: Manager, OrderIntent, CompanySearch, FieldValidation.

**Alternatives Considered**:
- Single monolithic file (rejected: hard to maintain)
- ES6 modules with bundler (rejected: version compatibility)

**Rationale**: 
- Separation of concerns
- Individual component testing
- Clear responsibilities

**Consequences**:
- Multiple script files need load order management
- Inter-module communication via manager
- Priority-based registration required

---

# PROPOSED — NOT IMPLEMENTED — company-search / sole-trader restructuring (TWO-40)

**Status: DESIGN ONLY, awaiting Doug's review. No code has been written for anything below.**
Written 2026-08-10 against `origin/staging` @ `0ddad20`. Items are numbered per Doug's own
consolidated list (#6/#9, #8, #12, #13).

Items **#3 and #7** of the same list are implemented — see PR #154. **Item #1, the config-key rename,
shipped separately in 2.7.6**: the key is now spelled `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS` everywhere
in live code, in a deliberately simple global-tier-only form (see the SUPERSEDED section at the end for
what a tier-safe rename would have required, and why it was not built). The designs below use the new
spelling; item #1 landed independently of everything here.

**Every `file:line` below is a HINT, verified against `origin/staging` @ `0ddad20` and nothing else.**
PR #154 touches most of the files cited below — it deletes ~55 lines from `TwoCompanySearch.js`, ~72
from `override/classes/form/CustomerAddressFormatter.php`, ~20 from
`controllers/front/orderintent.php` (which carries most of §#12's citations) and ~10 from
`TwoCheckoutManager.js`, and rewrites ~32 lines of `twopayment.php` — so essentially every number here
shifts once it merges. Re-derive with `git grep -n <symbol> <ref>` against a
freshly fetched ref before acting on any of them — never from a working tree. Several numbers in the
first draft of this document were already wrong for exactly that reason.

---

## #6 / #9 — why does the company selection persist at all, and for an hour?

### What exists today

Four cookie keys, all written server-side, all sharing one expiry:

| key | written at |
|---|---|
| `two_company_name` | `controllers/front/orderintent.php:371` (`ajaxProcessSaveCompany`), `:976` (`storeCompanyDataInSession`), `twopayment.php:15825` (`hookActionCustomerAddressSave`) |
| `two_company_id` | `orderintent.php:372`, `:977` |
| `two_company_country` | `orderintent.php:374`, `:995` |
| `two_company_address_id` | `orderintent.php:377`, `:997` |

Expiry is `Twopayment::COOKIE_EXPIRY_ONE_HOUR = 3600` (`twopayment.php:282`), re-stamped on
every write: `orderintent.php:293`, `:379`, `:1002`, `twopayment.php:14569`, `:14593`, `:15868`.

The browser writes them by calling `ajaxProcessSaveCompany` fire-and-forget from
`TwoCompanySearch.persistCompanyToCookie()` (`views/js/modules/TwoCompanySearch.js:4006`) and clears them via `clearPersistedCompany()` (`:2376`, called
from `clearSelectedCompany()` at `:2341`).

The cookie is the **first** source consulted by both readers — `Twopayment::getCompanyDataWithFallbacks()`
priority 1 at `twopayment.php:8795-8808`, and the order-intent handler's own chain at
`orderintent.php:824`. So it outranks the address.

### Why it exists — answered

**It is compensating for real page navigations, not for a refresh and not for cross-order memory.**

PrestaShop's checkout is multi-step with a genuine document load between the address step and
the payment step. In-memory JS state in `TwoCompanySearch` does not survive that, and the
address step's `input[name='company']` — the only field that carries the selection in the DOM —
is not on the payment step at all. The payload builder runs server-side at the payment step and
needs the org number the buyer picked one navigation earlier. The cookie is the only thing
bridging that gap today.

Two pieces of evidence that **cross-order memory was never the intent**:

1. `TwoCompanySearch.storeCompanyDataInSession()` — the browser-side `sessionStorage` write —
   was dead code and is deleted in PR #154. Even that dead path used `sessionStorage`
   (tab-lifetime), not `localStorage`. Nobody ever built a durable store.
2. The surviving comment block it sat under said the opposite of persistence: *"Company data is
   now handled by form fields - no complex server persistence needed"* (also deleted in #154).

And the 1-hour figure is **arbitrary**. `COOKIE_EXPIRY_ONE_HOUR` is a generic module constant
shared with the payment-term cookie (`orderintent.php:379`) and other unrelated writes; there is
no comment anywhere justifying an hour for the company selection specifically, and
`twopayment.php:11271` notes other code paths merely *rely on* it being that value. It is
a house default, not a decision about how long a company selection should be trusted.

So Doug's framing is right: nothing needs remembering beyond one checkout attempt. What the
cookie is actually doing is carrying state across ~2-3 page loads inside one attempt, and it
happens to keep doing so for an hour afterwards as a side effect.

### Proposed design

**Scope the persistence to the cart, and kill the standalone lifetime.**

1. **Bind every stored company record to a cart id.** Store one structure rather than four loose
   keys — `two_company = {cart_id, name, id, country, address_id}` — and have the reader discard
   it outright when `cart_id !== $this->context->cart->id`. A cart id changes on order placement,
   so a selection cannot survive into a future order even if the cookie physically outlives the
   checkout. This is the single change that delivers Doug's requirement, and it is strictly
   stronger than shortening the TTL.
2. **Move it from the PrestaShop cookie to the PHP session** where the shop has one, since the
   semantics wanted are session-scoped, not time-scoped. PrestaShop's `Cookie` object *is* its
   session substrate in 1.7/8.x, so in practice this means dropping the explicit
   `setExpire(time() + COOKIE_EXPIRY_ONE_HOUR)` calls for the company keys and letting them ride
   the shop's own session cookie lifetime — **not** re-stamping a fresh hour on every write.
   The four `setExpire` sites that are company-related (`orderintent.php:293`, `:379` is the
   payment term and stays, `:1002`, `twopayment.php:15868`) need separating from the ones that
   are not; today they are literally the same call.
3. **Keep the two existing invalidation guards and make them cheaper, not weaker.** The
   country-mismatch wipe and the address-switch wipe (`twopayment.php:8789` reads
   `two_company_address_id`; `:8799` compares it against the current address) exist because the cookie can outlive
   the state it described. Cart-scoping does not replace them — a buyer can switch address inside
   one cart — so they stay. But `two_company_address_id` becomes a field of the cart-scoped
   record instead of an independent key that can drift out of step with the other three.
4. **Drop the "unverified cookie" tier.** `getCompanyDataWithFallbacks()`'s cookie fallback for
   the company *name* (`twopayment.php:8820-8825`) reads the cookie again after the validated
   read already declined it. With a cart-scoped record there is one record and one verdict on it.

### Implication for the generation counters — read this before touching them

`_enrollGeneration` and `_tokensGeneration` in `TwoSoleTraderPlus`/`TwoCompanySearch` are
**not** refereeing the cookie against in-memory state, and shortening the cookie's life will not
retire them. Per the TWO-40 changelog entry, they referee two *async* races that are entirely
browser-side:

- `_enrollGeneration`, bumped on every `cancelEnrollment()`, stops an in-flight buyer-lookup or
  `saveCompany` response from publishing a sole-trader identity over a real company the buyer
  picked while it was in flight.
- `_tokensGeneration`, stamped onto tokens at mint time, stops a *stale hosted-signup popup*
  finishing on its own — long after the buyer moved on — from issuing a brand-new lookup that
  would re-capture a misleadingly fresh `_enrollGeneration` and look legitimate.

Neither race involves the cookie. **Both counters must survive this change unchanged.** The one
real interaction: `cancelEnrollment()` and `clearSelectedCompany()` both need to invalidate the
new cart-scoped record exactly where they invalidate the cookie today
(`TwoCompanySearch.js:2341` → `:2376`), and the record's cart-id check must not be treated as a
substitute for a generation check — it is a coarser guard on a different axis.

**Open question for Doug:** should a *placed* order's company selection be readable at all after
placement (order confirmation page, admin re-render)? Cart-scoping makes it unreadable. If
anything downstream reads the cookie post-placement this design breaks it, and I have not
audited the confirmation path for that.

---

## #8 — tile-mode's address-side layer is gated on the wrong thing

### The conflation, precisely

There are **two independent questions** and the code currently answers them with one value:

- *Where does the search UI render?* — `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS` (address area vs payment
  tile; renamed from `PS_TWO_ENABLE_COMPANY_NAME` in 2.7.6, see #1).
- *May a company selection write into the address form's fields?* — `PS_TWO_ADDRESS_LOOKUP`,
  admin label "Autofill company address".

Every site where the first currently decides the second:

| site | what it does |
|---|---|
| `twopayment.php:2535-2537` (`getAddressLookupEnabled()`) | **force-returns `'0'`** when `isCompanySearchInAddressArea() !== '1'` — the merchant's `PS_TWO_ADDRESS_LOOKUP` is never even read in tile mode |
| `twopayment.php:2597-2602` (`isAddressLookupSettingAvailable()`) | declares the setting *unavailable* from the search's position, which greys the field out in admin and refuses it on save |
| `views/templates/admin/configuration.tpl:102`, `:128` | the admin JS that disables/unchecks the dependent field, keyed off the location radio |
| `TwoCheckoutManager.js:2400` | hardcodes `addressLookupEnabled: false` on the tile-mounted `TwoCompanySearch`, explicitly "never inherited from the merchant's general auto-fill toggle" |
| `TwoCheckoutManager.js:2407` | passes the merchant value through **only** on the address-area mount |
| `twopayment.php:2243` (admin `desc`) | tells the merchant in prose that the setting "is unavailable and forced off" in tile mode |

The two consumers of the resulting flag are `TwoCompanySearch.writeOrganizationToAddressIdentifiers()`
(`views/js/modules/TwoCompanySearch.js:1643`) and `autoFillAddress()` (`:3624`), both via
`isAddressLookupEnabled()` (`:1631-1632`).

### Why the current gate was chosen, and why Doug is right that it is wrong

The tile mount's hardcoded `false` is not arbitrary — the comment at `TwoCheckoutManager.js:2382-2399`
records a real defect: `autoFillAddress()` writes to `input[name='address1'/'postcode'/'city']`
**by global selector**, with no awareness of which control triggered it. So a company picked in
the tile would rewrite an address the buyer is not looking at. Gating on mount location made that
impossible.

But it made it impossible by removing the merchant's control instead of fixing the write. The
merchant has a switch that means exactly "populate the address from the company"; if they turn it
on, populating the address is what they asked for, wherever the search happens to render. And the
current shape has a concrete cost beyond principle: a shop that has never re-saved its advanced
settings since the search moved to the tile reports `'0'` for a setting whose stored row says
`1` — the comment at `twopayment.php:2531-2534` describes the read-side gate as existing
specifically to stop the admin form, the stored row and the checkout JS disagreeing. That is
a workaround for the conflation, not a feature.

### Proposed separation

1. **`getAddressLookupEnabled()` reads `PS_TWO_ADDRESS_LOOKUP` and nothing else.** Delete the
   `isCompanySearchInAddressArea()` early return at `twopayment.php:2535-2537`. Absent/empty
   still defaults to `'1'` (`:2541-2543`), unchanged.
2. **Delete `isAddressLookupSettingAvailable()`** (`twopayment.php:2597-2602`) and its uses. The
   setting is always available because it always means something. Remove the dependent-field
   greying in `views/templates/admin/configuration.tpl:102`, `:128`, and rewrite the admin `desc`
   at `twopayment.php:2243` — which currently promises the setting is forced off in tile mode,
   and which is also wrong on a second count today (it claims the lookup writes the "VAT number"
   field; the write side has never touched `vat_number`, see `TwoCompanySearch.js:1672-1691`).
   Both catalogue entries need updating (`translations/sv.php:441` at least).
3. **`TwoCheckoutManager.js:2400` passes the merchant value through**, same expression as the
   address-area mount at `:2407`.
4. **Fix the write that made (3) unsafe — this is the load-bearing part and must land in the same
   change.** `autoFillAddress()` must resolve its target fields relative to a mount-supplied
   root rather than by global selector. Concretely: give `TwoCompanySearch` an
   `addressFormRoot` config (the form element containing the mounted field for the address-area
   mount; the checkout's address form for the tile mount) and scope every
   `input[name='…']` lookup in `autoFillAddress()` (`:3624`ff) and
   `addressIdentifierFields()` (`:1692`) to it. Without this, (3) reintroduces the exact defect
   the hardcoded `false` was protecting against.

**Open question for Doug:** in tile mode the address form is usually **not on the page** at the
payment step. With the gate removed and the merchant switch on, the correct behaviour is "write
if the fields are there, no-op if they are not" — which is already what the existing
`if (field.length === 0) return;` guards do (`TwoCompanySearch.js:1654`, `:1717`). Confirm that
silently no-op'ing is what you want, rather than deferring the write until the address form
reappears.

---

## #12 — sole-trader country must come from the live form, never a saved value

### What PR #153 established, and what reverses

`resolveSoleTraderCountryIso()` (`controllers/front/orderintent.php:235-256`) is a three-tier
trust-ordered chain:

| tier | source | line |
|---|---|---|
| 1 | the cart's **invoice address** — "never overridden by anything the request carries" | `:239-244` |
| 2 | a posted `country`, accepted only as `/^[A-Z]{2}$/` | `:246-249` |
| 3 | the cart's **delivery address** | `:251-253` |
| — | `''` → the caller refuses (`:182-185`) | `:255` |

Doug now wants tiers 1 and 2 swapped: the live in-page selection wins, always.

### The security concern is confirmed disproven

The docblock's own reasoning (`:150-165`) already says the posted tier is not a privilege
escalation, and the code confirms every claim:

- **`mintTokens()` takes no country at all** — `classes/TwoSoleTrader.php:335`, signature
  `mintTokens($module)`. It posts to `/registry/v1/delegation` and `/autofill/v1/delegation`
  with fixed scope payloads (`:337-344`); no country is in either body. The tokens are
  country-independent, so there is no per-country capability to escalate into.
- **Country is used only for the availability gate**, server-side, on every call:
  `TwoSoleTrader::isAvailable($this->module, $countryIso)` at `orderintent.php:187`. A posted
  country can therefore only move the answer from "unresolved" to *the registry's own answer for
  a real country*.
- **The browser can already learn that answer for any country it likes** from the
  `soleTraderAvailability` action, which answers a client-supplied country by design.

So promoting the posted tier has **no security consequence**. It is purely a correctness question.

### Proposed change

1. **Posted country becomes tier 1** and the only source in the normal case. Keep the exact
   `/^[A-Z]{2}$/` shape check at `orderintent.php:247` — it is what stops a junk value reaching
   the registry, and it is not the thing that was doing security work.
2. **Delete the invoice-address tier entirely.** Not demoted — deleted. A committed invoice
   address is precisely the stale value Doug is ruling out, and leaving it as a lower tier means
   it silently wins whenever the POST is missing a country for an innocuous reason, which is the
   current bug wearing different clothes.
3. **Delivery address becomes the sole last-resort tier**, reached only when no country was
   posted at all — an older cached script, a stripped body. Keep the fall-through rule from
   `:241-243` (an address that has no resolvable ISO falls through rather than terminating).
4. **Rewrite both docblocks — `ajaxProcessSoleTraderTokens()`'s and `resolveSoleTraderCountryIso()`'s own.** It currently argues at length for the
   invoice-address-first ordering, including the now-obsolete framing of the posted tier as a
   grudging middle-ground concession. Leaving that text in place after inverting the code is the
   exact failure mode that sent an agent chasing a fixed regression in the VAT design doc.
5. `tests/SoleTraderTokenPreconditionSpec.php` has a
   `testPostedCountryCannotConjureAvailability` case that stays valid and becomes *more*
   important — it is the test proving (2) did not weaken the gate. Add its mirror: a cart WITH
   an invoice address in country A, a posted country B, asserting the gate is evaluated against
   **B**.

**Note the coupling to `#13`:** whether the "live in-page selection" means the shipping select or
the billing select is decided by `#13`, not here. `resolveSoleTraderCountryIso()` only sees
whatever the browser posts; the browser-side decision about *which* select to read is `#13`'s.

---

## #13 — two distinct modes: ENABLED writes, DISABLED reads

### Doug's clarification restated as a contract

| `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS` (renamed in 2.7.6, per #1) | what this mode is about | behaviour |
|---|---|---|
| **enabled** (`'1'`, address area) | **WRITING** | the buyer searches in the address they see first/by default; the *other* address, if they have indicated the two differ, is auto-populated to match (company + country) |
| **disabled** (`'0'`, tile) | **READING** | address-field layout and behaviour are untouched — tile UI exactly as today; the tile search's and the sole-trader flow's country comes from whichever country is **currently selected on the page** for the billing/invoice address field |

These are genuinely orthogonal and the current code conflates them at both ends: `#8` shows the
enabled/disabled switch deciding a *write* permission it has no business deciding, and `#12` shows
the *read* falling back to a server-saved value instead of the live one.

### Platform default-address confirmation

Doug's premise holds for PrestaShop, and the repo states it in its own words:
`override/classes/form/CustomerAddressFormatter.php:90-93` — *"PrestaShop collects the SHIPPING
address first and only reveals the billing block when the buyer ticks 'Billing address differs
from shipping address', so most buyers never saw either field"*. That comment is the record of a
real defect — two fields were moved out of the billing block for exactly this reason — so it is
load-bearing evidence, not a guess.

So: **PrestaShop = shipping-first**, billing revealed conditionally. Magento and Hyvä are the
same shape. **WooCommerce is billing-first** — and that inverts *both* halves of the contract for
that platform, see the parity flags below.

### Enabled mode — the WRITE side, concretely

Search runs in the shipping address block (the default-visible one). On a confirmed selection,
`TwoCompanySearch.onCompanySelected()` (`views/js/modules/TwoCompanySearch.js:3398`) currently
writes company + identifiers + address fields into whatever `input[name='…']` it finds globally.

What would change:

- **`autoFillAddress()` (`:3624`) and `addressIdentifierFields()` (`:1692`) become root-scoped**,
  exactly as `#8` step 4 requires. Same prerequisite, one implementation — do `#8` and `#13`
  together or not at all.
- **A new mirror step** runs after the primary fill: if the billing block is present and revealed
  (the "addresses differ" checkbox is ticked), copy `company` and the country select's value from
  the block the search ran in into the billing block. Company **name** and **country** only, per
  Doug — not street/postcode/city, which are legitimately different when the buyer has said the
  addresses differ.
- **The mirror must respect `data-two-autofilled-value`.** That marker (written at `:1664` and
  `:3680`, read at `:1720`, `:3660`, `:1608`) is what distinguishes "the plugin put this here"
  from "the buyer typed this". A mirror that overwrites a billing company the buyer typed by hand
  is the same class of bug as the tile-writes-the-address defect. So: write into the billing
  block's fields only when they are empty, or when their current value still equals their own
  marker.
- **The mirror must be gated on `PS_TWO_ADDRESS_LOOKUP`** like every other write, via
  `isAddressLookupEnabled()` (`:1631`). It is address population; it belongs behind the address
  population switch.
- **Reactivity:** the billing block is revealed *after* the search may already have run. So the
  mirror also needs to fire on the reveal, not only on selection — a listener on the
  "addresses differ" checkbox that re-runs the mirror from the currently confirmed selection.

### Disabled mode — the READ side, concretely

Nothing about address-field layout or behaviour changes. What changes is which DOM node the
country resolvers read.

- `TwoCompanySearch.getCurrentCountry()` (`:3295`), `TwoSoleTrader.billingCountry()`
  (`views/js/modules/TwoSoleTrader.js:372`) and
  `TwoOrderIntent.getCurrentAddressCountryISO()` (`views/js/modules/TwoOrderIntent.js:560`)
  all currently start from `select[name='id_country'], select[name='country']` — the **first
  match in the document**, which in shipping-first PrestaShop is the shipping select.
- In disabled mode the contract says read the **billing/invoice** select when one is present and
  revealed, falling back to the shipping select when it is not (the common case where the buyer
  has not said the addresses differ, so the two are the same address anyway).
- Whatever those resolvers produce is what gets POSTed as `country` to the sole-trader token
  endpoint, which `#12` makes tier 1. So `#12` and `#13`'s read side are one feature: `#12` is the
  server half, `#13`-disabled is the browser half. **Neither is correct without the other** —
  #12 alone makes the server trust a posted value that the browser may have read off the wrong
  select.

### This is also the moment to collapse the mirrored resolvers

The read-side change has to be made in **four** places today, not three:
`TwoCompanySearch.getCurrentCountry()` (`:3295`), `TwoSoleTrader.billingCountry()` (`:372`),
`TwoOrderIntent.getCurrentAddressCountryISO()` (`:560`), and
`TwoCheckoutManager.getSelectedCountryIso()` (`views/js/modules/TwoCheckoutManager.js:2605`,
which delegates to `TwoOrderIntent` when available and otherwise re-implements the chain inline).
They already disagree — `TwoCompanySearch.js:3312` reads only `data-iso-code`/`data-iso` while
`TwoSoleTrader.js:380`, `TwoOrderIntent.js:584` and `TwoCheckoutManager.js:2614` also read
`data-country-iso`; and `TwoOrderIntent` alone has a try/catch and a five-selector list.

Making a which-select-do-I-read decision four times, into four chains that are already not
equivalent, is how one of them ends up reading the shipping select forever. `TwoCompanyNumber.js`
is the working precedent for a shared helper in this directory (registered at priority 200 in
`twopayment.php:4589-4598`, ahead of every consumer, `async: false`) and the Jest harness loads
extra files with a single `loadScript` call. **Recommendation: extract `TwoCountry.js` as a
prerequisite of `#13`**, keeping only each module's *terminal* fallback per-module (they
legitimately differ: `window.twopayment.billing_country` vs
`config.billingCountry || config.shopCountry` vs `''`). Note that
`tests/CompanySearchCountrySourcingSpec.php:432-445` is a source-text grep asserting
`getCurrentCountry()` still contains specific reads, and must be retargeted in the same change.

The hardcoded country-name→ISO maps are **two** copies, not three (Doug's #10 said three):
`TwoCompanySearch.js:3373-3389` and `TwoSoleTrader.js:448-464`, currently byte-identical, 10
countries, en/es/fr/nl/no/sv. They ride along in the same extraction.

### Cross-platform parity flags

- **WooCommerce is billing-first.** Both halves invert: the address the buyer sees first *is* the
  billing address, so enabled-mode's mirror writes billing→shipping, and disabled-mode's read has
  no "reveal the other block" condition to wait for. A naive port of a shipping-first
  implementation reads and writes the wrong block on WooCommerce in every case.
- **Magento / Hyvä are shipping-first like PrestaShop**, but Hyvä's company capture has its own
  tile-input-is-the-submit-carrier behaviour, so the mirror's target selectors are not portable
  as written.
- The enabled-mode mirror has **no equivalent on any platform today**, so it is new behaviour
  everywhere and should ship on PrestaShop first as the pilot, matching how the three-chip
  selector was rolled out.

---

## Cross-cutting: what has to land together

- `#8` step 4 (root-scoping the address writes) is a **hard prerequisite** for both `#8` step 3
  and `#13`-enabled. Landing either without it reintroduces the tile-rewrites-a-hidden-address
  defect.
- `#12` (server trusts posted country) and `#13`-disabled (browser posts the *right* country) are
  two halves of one feature.
- `TwoCountry.js` extraction is a prerequisite of `#13`-disabled unless we are content to make the
  same decision in four already-divergent chains.
- `#6`/`#9` is independent of all of the above and can ship on its own.

## Open questions for Doug

1. **`#6`/`#9`:** may a placed order's company selection still be read after placement
   (confirmation page, admin)? Cart-scoping makes it unreadable and I have not audited that path.
2. **`#8`:** with the gate removed and the merchant switch on, tile-mode writes should silently
   no-op when the address form is not on the page. Confirm, rather than deferring the write.
3. **`#13`-enabled:** the mirror copies company **name + country** only. Confirm that street /
   postcode / city are deliberately excluded when the buyer has said the addresses differ.
4. **`#13`-enabled:** when the buyer has NOT ticked "addresses differ", there is one address and
   nothing to mirror. Confirm that is a no-op and not "populate a hidden billing block anyway".
5. **`#10` correction:** there are **two** hand-mirrored country-name maps, not three. Confirm
   nothing is expected in a third place (PHP, a `.tpl`) — I swept the whole tree and found none.

---

## Addendum — corrections to the premises in items #4, #8, #10, #12

Recorded here because three of Doug's items rest on a count or a claim that turned out slightly
off, and the design above already assumes the corrected version.

1. **#4 / #10 — the ISO chain is mirrored FOUR times, not three.** The fourth is
   `TwoCheckoutManager.getSelectedCountryIso()` (`views/js/modules/TwoCheckoutManager.js:2605`),
   which delegates to `TwoOrderIntent` when available and otherwise re-implements the chain
   inline. It is easy to miss because it is not named like the other three.
2. **#4 — the divergence Doug remembered is real and is the right way round.**
   `TwoCompanySearch.js:3312` reads only `data-iso-code` / `data-iso`;
   `TwoSoleTrader.js:380`, `TwoOrderIntent.js:584` and `TwoCheckoutManager.js:2614` also read
   `data-country-iso`. So the company search alone can fail to resolve a country on a theme
   that only emits `data-country-iso` — and it is the module that most needs the answer.
3. **#10 — there are TWO hand-mirrored country-name maps, not three.**
   `TwoCompanySearch.js:3373-3389` and `TwoSoleTrader.js:448-464`. Currently byte-identical: 10
   countries, six languages (en/es/fr/nl/no/sv). No third copy exists anywhere in the tree — PHP,
   JS or `.tpl`. Being identical *today* is what makes them dangerous: nothing detects the first
   divergence, and the language lists are long enough that a partial edit looks complete.
4. **#12 — the security precondition is confirmed disproven, from the code rather than the
   comment.** `TwoSoleTrader::mintTokens($module)` (`classes/TwoSoleTrader.php:335`) takes no
   country parameter and neither delegation payload carries one (`:337-344`). Country is used
   only for `TwoSoleTrader::isAvailable()` at `controllers/front/orderintent.php:187`. So there
   is nothing for a spoofed country to escalate into.
5. **#8 — the admin `desc` for "Autofill company address" is wrong on a second count**, beyond
   the tile-mode claim: it tells the merchant the lookup overwrites "the organisation-number
   fields (DNI / VAT number)". The write side has never touched `vat_number`, and as of PR #154
   the read side does not either. This string is translated, so fixing it is a catalogue pass —
   left alone in #154 deliberately.

### One more open question, from the #154 review

6. **`controllers/front/orderintent.php:574`'s `incomplete_company` message says "go back to your
   billing address and search for your company name".** That instruction is impossible to follow
   when `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS='0'` — there is no search in the address step, it is in
   the payment tile the buyer is already looking at. Pre-existing, but removing the `vat_number`
   fallback makes this branch materially more reachable, and the merchants newly hitting it are
   exactly the ones whose buyers cannot act on the wording. Same text at `twopayment.php:14222`
   for the provider-side `organization_number` value_error. Both are translated. Wants either a
   location-neutral rewording or a branch on the location switch — a small ticket of its own,
   not part of #8/#12/#13.

---

# SUPERSEDED — #1, the company-search location key rename (TWO-40)

**Status: the rename SHIPPED in 2.7.6 as `PS_TWO_ENABLE_COMPANY_NAME` -> `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS`,
in a deliberately SIMPLE global-tier-only form — not the tier-exact design worked out below.** Doug's
explicit ruling: with no live merchants on this plugin there is no multistore override to lose, so the
tier-exact migration is not worth its risk or its complexity. `upgrade/upgrade-2.7.6.php` does a
resolving read, one `updateValue()`, and a name-wide `deleteByName()`, and its own header states the
loss it accepts.

**Everything below this heading stands as the record of what a SAFE rename requires**, if the plugin
ever acquires multistore merchants — at which point the shipped script is not sufficient and this is
the work. It is also the record of three real defects, kept because each of them was found by review
rather than by tests, and none of the tests could have found them.

Doug's #1 originally asked for the rename to `PS_TWO_COMPANY_SEARCH_LOCATION` with a real migration,
and classified it as mechanical/safe. **The premise is right and the classification is wrong.** The
rename itself is two seds; a *tier-safe migration* is genuinely hard, and three adversarial review
rounds each found a different variant of silent merchant data loss in it. Every finding in all three
rounds was in this item — `#3` and `#7` were clean throughout, which is why they shipped first and
this did not.

The confirmation that the rename is purely a location switch **does hold**: `isCompanySearchInAddressArea()`
resolves to the `'1'`/`'0'` string the checkout JS compares against, `'1'` = address area, `'0'` =
payment tile, and the one shared control exists either way. The old name was genuinely misleading,
which is why the rename was worth doing at all.

## Why it is hard: one asymmetry and one hidden dimension

**The asymmetry.** `Configuration::deleteByName($key)` is NAME-WIDE and unconditional — there is no
per-shop, per-group or per-tier variant. Every writer (`updateValue`, `updateGlobalValue`) and every
reader (`get`, `getGlobalValue`, `hasKey`) is tier-scoped. So the obvious read-write-delete rename
reads one tier's value and destroys every other tier's. Nothing else in the module hits this: the other
every other `Configuration::get()` call in `twopayment.php` only ever READS, so being context-scoped merely
makes them narrow, not lossy.

**The hidden dimension.** PrestaShop has **three** configuration tiers, not two:

| tier | `id_shop_group` | `id_shop` | written by |
|---|---|---|---|
| global | NULL | NULL | `updateGlobalValue()`, or `updateValue()` under `Shop::CONTEXT_ALL` — which is what `install()` does |
| shop group | set | NULL | a bare `updateValue()` while the back office sits in a group context |
| shop | NULL | set | a bare `updateValue()` in a single-shop context |

## The three defects, in the order they were found

1. **Context-scoped read + name-wide delete.** Read one shop's value, wrote it, deleted every shop's
   row. A multistore install collapsed onto whichever value the upgrade happened to run in.
2. **`hasKey()` does not resolve; `get()` does.** `hasKey($k, null, null, $idShop)` is a bare `isset()`
   on the per-shop cache with **no** fallback to the global row — verified byte-identical in 1.7.6.9,
   1.7.8.11, 8.1.7 and 9.0.0. Since `install()` seeds GLOBAL rows, a per-shop `hasKey()` loop answers
   "no row" for every shop on a stock multistore install, carries nothing, and then deletes the global
   row anyway. Also: `hasKey()` counts a row holding `''` as SET while the resolver treats `''` as
   ABSENT, so an empty row suppressed the copy and the value was deleted uncopied.
3. **`get()` per shop resolves THROUGH the global row.** So writing back what it returns materialises a
   per-shop row for every shop even when the only row was global. Per-shop rows take precedence, so
   that permanently breaks the merchant's "all shops" save: they save, core writes the global row, the
   invented per-shop rows shadow it, the save silently does nothing. Fixing this tier-for-tier
   (global→global via `getGlobalValue`/`updateGlobalValue`, per-shop→per-shop via `hasKey`-per-shop)
   then **missed the shop-group tier entirely** — read by neither getter, and destroyed by the delete.

Note the shape: each fix was correct about the defect it targeted and introduced a new one, because
every one of them tried to *infer* which tier a row lived in from an API that resolves across tiers.

## How to actually do it

**Stop inferring tiers. Read `ps_configuration` directly and migrate each row at its own tier.**

```sql
SELECT id_configuration, id_shop_group, id_shop, value
  FROM ps_configuration WHERE name = 'PS_TWO_ENABLE_COMPANY_NAME'
```

For each row: if no `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS` row exists at the *same* `(id_shop_group, id_shop)`
tier (NULL-safe compare — MySQL `<=>`) and the value is usable, rename that row in place
(`UPDATE ... SET name = <new> WHERE id_configuration = <id>`). Then
`DELETE FROM ps_configuration WHERE name = <old>` and `Configuration::loadConfiguration()` to refresh
core's static caches. Renaming in place is tier-exact by construction and needs no tier reasoning at all.

Note `Configuration::updateValue()` **cannot** express a group-tier write: passing `$idShop = null`
makes core substitute the ambient shop rather than NULL. That is why this has to be SQL.

## Prerequisites before trying again

1. **A multistore leg in `.github/workflows/smoke.yml`.** The current upgrade-smoke job is single-shop,
   so no CI job anywhere exercises the multistore path against real core. Every one of the three
   defects above passed a fully green suite.
2. **A shop dimension in `tests/bootstrap.php`'s `Configuration` double.** It is a flat name→value
   array today: it cannot distinguish `hasKey`-per-shop from `hasKey`-global, cannot represent a global
   row shadowed by a per-shop one, and therefore cannot fail on any of these defects. A working version
   was built and then withdrawn with the rest — recover it from PR #154's branch history (`dd93a1f`)
   rather than rewriting it, and note the two corrections review found in it: branch on `$idShop`
   being TRUTHY (core does `if ($idShop)`, so `0` means the global tier), and model an ambient
   shop/group context or `updateGlobalValue()` and a bare `updateValue()` stay indistinguishable.
3. **A decision on the file-swap window** — see below.

## The file-swap window, which is a separate problem and does not go away

A deploy that only replaces module files does **not** run upgrade scripts; that needs the back-office
**Module Manager → Upgrade** action or `dev/ci/upgrade-module.sh`, and nothing else — in particular the
module's own *configuration* page does not run them, no PrestaShop code path executes `upgrade/*.php`
from there. The git-synced shops update by file swap. So between the swap and the upgrade actually being
run, the new key is absent while the old row is still in the DB, and a tile-mode merchant gets the
search back in the address area, on a live storefront, silently, for as long as nobody runs the upgrade.

Address autofill comes back with it **only where `PS_TWO_ADDRESS_LOOKUP` is absent or `1`**.
`getAddressLookupEnabled()` force-returns `'0'` while the search is not in the address area, so once the
resolver flips back to the address-area default it reads that row again — but a shop that picked tile
mode *through the admin form* had the row written to `0` by the same save (the write is gated on
`isAddressLookupSettingAvailable()`), so autofill stays off there. The autofill half of the window
therefore bites shops whose tile mode was seeded programmatically, as the e2e tile-location spec does,
not ones that clicked it in the back office.

The same window is also why the 2.7.6 copy is guarded on the **new** key, not only on the old one: a
merchant can save a position through the config page before any upgrade runs, and an unguarded copy
would later put the stale old row back over it. The guard uses the resolving read and treats `''` as
absent, deliberately not `Configuration::hasKey()` — which counts an empty row as set and would suppress
a copy the module itself needs.

**Doug ruled: no shim.** His instruction was "not a permanent alias", and 2.7.6 shipped with no read
shim of any kind — the window is real, unmitigated in code, and documented instead (in
`upgrade/upgrade-2.7.6.php`'s header and the CHANGELOG entry): running the upgrade once after a
file-swap deploy — Module Manager → Upgrade, or `dev/ci/upgrade-module.sh` — is a mandatory release
step. That is option two below. The three options are kept as the record of what was weighed.

- **A self-expiring read shim** — the resolver reads the old key when the new one is absent, with a
  spec that turns red once the declared version reaches 2.8.0 and names what to delete. Built and
  withdrawn with the rest; recoverable from `dd93a1f`. Note the trap that made it worthless first
  time round: `TwopaymentTestHarness` hardcodes `$this->version = '2.4.0'` and never calls
  `parent::__construct()`, so a version-compare against `$module->version` can NEVER fire — read the
  declared version out of `twopayment.php` and `config.xml` instead, as `UpgradeScriptVersionSpec` does.
- **No shim, plus a release-note requirement** that a back-office Module Manager visit is a mandatory
  release step. Honest, but relies on a human doing it.
- **Don't rename at all.** Worth genuinely considering. The benefit is readability of one config key;
  the risk is silent merchant data loss in a code path that has now produced three distinct variants
  of exactly that, on a module whose deploy pipeline does not reliably run migrations. A comment on
  `isCompanySearchInAddressArea()` explaining that the key's name is historical buys most of the
  readability for none of the risk.

**The standing recommendation at the time was option three, don't rename at all.** Doug overruled it and
took the rename with option two's file-swap handling and a global-tier-only migration, on the grounds
that there are no live merchants to lose data belonging to. It landed as its own PR, not bundled with
unrelated cleanup, which was the other half of the recommendation. The SQL migration and both CI/test
prerequisites were NOT built — they remain the price of admission the day this plugin has a multistore
merchant.

## Unrelated pre-existing bug found while doing this

`Configuration::get($key, $fallback)` is called as if argument 2 were a default value. Core's argument
2 is `$idLang` and core has no default parameter at all — `get()` does
`$idLang = self::isLangKey($key) ? (int) $idLang : 0`, and `isLangKey()` is false for every
non-multilingual key, so the stray argument is discarded and the call returns core's own `false`.

Ten sites pass a non-null second argument. Classified against `origin/staging` @ `0ddad20`:

| class | count | sites |
|---|---|---|
| loses an intended TRUTHY default | **4** | `twopayment.php:2746`, `:8626` (both `PS_TWO_ENABLE_TAX_SUBTOTALS`, default `1`); `:3093`, `:13978` (both `PS_TWO_ENVIRONMENT`, default `'development'`) |
| falsy default, harmless by accident | 2 | `:13977` (`false`), `:14750` (`0`) |
| legitimate `$idLang` use | 4 | `:1748`, `:1749`, `:4818`, `:4819` |

The `PS_TWO_ENABLE_TAX_SUBTOTALS` pair is harmless in practice only because `install()` seeds that key.
The `'development'` pair is the more interesting one: an unseeded environment reads as `false` rather
than development, and nothing seeds it on an upgrade path predating the key.

Re-derive rather than trusting these numbers — an earlier draft of this paragraph carried HEAD line
numbers while claiming to cite `origin/staging`, which is the exact failure the header warns about:

    git grep -nE "Configuration::get\('[A-Z_0-9]+', *[^)]" origin/staging -- twopayment.php

The old test double implemented `get($key, $default = null)` — the signature the module wished for —
which is exactly what hid this. Wants its own small ticket.

## Also unverified, flagged rather than chased

`controllers/front/orderintent.php`'s `$address->companyid = $companyId;` assigns a **dynamic property**
on an `ObjectModel` subclass. PHP 8.2 deprecates that unless the class carries
`#[AllowDynamicProperties]`, and I could not confirm whether PrestaShop's `ObjectModel` does. Entirely
pre-existing and untouched by PR #154 — but that PR promotes the branch READING this property to
priority 2 in `extractOrgNumberFromAddress()` and documents it as load-bearing, so if the assignment
ever starts emitting deprecations (or stops working under a future PHP), the read is the thing that
silently returns empty. Worth ten minutes against a real PS 8.2+ shop; a typed column or an explicit
carrier would be the durable fix.
