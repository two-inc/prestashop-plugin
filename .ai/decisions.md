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

## [2026-08-14] All Three Service URLs Are Independently Dev-Overridable Through One Shared Gate

**Context**: The plugin talks to three Two services - the checkout API, the merchant portal and
the hosted checkout-page app that serves the sole-trader signup. Two of them already honoured a
dev-mode-only env var (`TWO_API_BASE_URL`, `TWO_PORTAL_BASE_URL`), the third did not, so a dev
editing the checkout-page app had no way to point a shop at their own copy of it while leaving the
API on staging. `TwoSoleTrader::getSignupPageUrl()` is `static` and the existing gate
(`Twopayment::getDevEnvOverride()`) was a private *instance* method, so it could not be called from
there at all.

**Decision**: Added `TWO_CHECKOUT_BASE_URL`, and lifted the gate into one shared
`Twopayment::getDevModeEnvOverride($name)` - `public static`, so the static signup-URL resolver can
use it. The private instance `getDevEnvOverride()` stays as a thin delegate, unchanged for its two
existing callers. All three variables resolve independently; each falls back to its own
environment-keyed host map when unset.

**Alternatives Considered**: (a) duplicate the `_PS_MODE_DEV_` check inside `TwoSoleTrader` - a
duplicated security gate is a gate that gets half-removed; (b) plumb a `Twopayment` instance into
`getSignupPageUrl()` - its one caller (`controllers/front/orderintent.php`) has a module instance,
but that widens the signature of a resolver that needs no module state; (c) a new config-provider
abstraction - far more structure than one `getenv()` behind one constant check needs.

**Consequences**: The dev-mode gate now has exactly one implementation, and it is the security
barrier for all three: with `_PS_MODE_DEV_` undefined or false every override returns null, so a
production shop ignores these variables even when they are present in its environment.
`TwoSoleTraderSpec` covers both sides of that gate by running a child PHP process
(`tests/fixtures/dev-mode-url-probe.php`) - `_PS_MODE_DEV_` is a constant, so one process cannot
exercise both. Also `rtrim($override, '/')` before appending `/soletrader/signup`, since a
hand-typed env var may carry a trailing slash the host map never does.

---

## [2026-08-11] Sole-Trader Enrolment Writes Its Identity And Address Into The Form

> **Supersedes the 2026-08-10 entry below**, which recorded the opposite decision and one
> statement of fact that was simply wrong. Both corrections are set out here rather than by
> quietly editing that entry, so the reversal is legible.

**Context**: A completed sole-trader enrolment populated nothing at all. `applyBuyer()` in
`TwoSoleTrader.js` received the completion response, read `company_name` and
`organization_number` off it with the right field paths, and then wrote neither of them - nor
any address field - to any input. It posted to the `saveCompany` session action, published an
in-memory selection through `TwoCheckoutManager`, called container-scoped status/prompt UI that
silently no-ops on the address-editor page, and dispatched a `two:sole-trader-ready` event with
no listener anywhere. Reported as: *"Sole trader workflow is not actually populating company name
or address from the autofill call... It's not just address that is failing to populate. It's
company name/number as well. Absolutely nothing is being populated. Critical bug."*

**The 2026-08-10 entry's factual error, corrected**: it claimed a street/postcode/city autofill
was "not possible as that endpoint is consumed today, because the response carries no address
payload at all". That is false. `/autofill/v1/buyer/current` carries a full address payload.
Captured live from a real signup completion against staging:

```json
{
    "billing_address": {"apartment":"","building":"Wharf Lane","city":"Ashford","country":null,"organization_name":null,"postal_code":"TN23 1AA","region":"","street":"Wharf Lane"},
    "company_name": "Sole Trader Test Co",
    "country_code": "GB",
    "email": "buyer@example.test",
    "first_name": "Alex",
    "last_name": "Buyer",
    "organization_number": "TWO:ST123456789012",
    "phone_number": "+440000000000",
    "shipping_address": null
}
```

No API contract confirmation was needed; the data was already there.

The payload above is the captured one with **every personal value replaced** - name, email, phone,
address and the synthetic identifier are all substitutes. This is a public repository; the SHAPE is
the finding, and the shape is what is reproduced. The same substitutes are used in
`tests/js/sole-trader-writeback.test.js` so the fixture and this record agree.

**Decision**: The completion adopts the enrolled identity into the form the buyer is looking at,
through `TwoCompanySearch.adoptSoleTraderBuyer()`. `two:sole-trader-ready` is unchanged and still
has no listener - the adoption is a direct call from `applyBuyer()`, not an event contract.

What is written:

| Response field | Destination | Notes |
| --- | --- | --- |
| `company_name` | visible `company` input | marked `data-two-autofilled-value`; skipped when blank |
| `organization_number` | hidden `companyid` + `data-two-company-name` tag | via `markOrganizationFieldSelected()`; **gated on a non-blank `company_name`** |
| `organization_number` | visible `dni` input | via `writeOrganizationToAddressIdentifiers()`; same name gate. **No value is exempt** — see the internal-identifier ruling below |
| `billing_address.building` / `.apartment` | `address1` (street moves to `address2`) | most-specific locator takes the first line |
| `billing_address.region` | state select, else appended to `city` | see the region ruling below |
| `billing_address.street` | `address1` | via `autoFillAddress()` |
| `billing_address.postal_code` | `postcode` | via `autoFillAddress()` |
| `billing_address.city` | `city` | via `autoFillAddress()` |

What is deliberately NOT written:

- **`country_code`.** The registered country is not the country this enrolment was authorised
  for. The token, and the session company `saveCompany` has just stored, were minted against the
  country resolved from the LIVE FORM (decision `#12`), and
  `getTwoValidatedSessionCompanyData()` discards the entire session company the moment the saved
  country disagrees with the cart's invoice-address country. Writing the registered country over
  the form's would destroy the enrolment it is completing. The two agreeing needs no write; the
  two disagreeing is exactly where writing is wrong.
**Every field in the response now lands somewhere (Doug's ruling).** Nothing is dropped for being
inconvenient to attribute:

- **`building` / `apartment` → `address1`, with `street` moving to `address2`.** Where a building or
  apartment is given it is the more specific locator and takes the first line; where neither is
  given the street takes the first line and the second is left alone. Both present are joined
  most-specific-first (`"Apartment 4, Mill House"`).
- **No de-duplication against the street**, on Doug's explicit ruling: *"it is valid for some
  addresses to have a matching first and second line so deduping would be wrong."* An earlier round
  proposed exactly that dedup and it was rejected — an address whose `building` equals its `street`
  writes that text to both lines.
- **`region` → the form's state/county select where one exists, otherwise appended to `city` with a
  comma** (`"Ashford, Kent"`). The state match is best-effort by necessity: the response carries a
  region NAME with no code, PrestaShop needs a shop-local state id, so the only join available is on
  the visible label (trimmed, case-folded, `data-iso-code` also accepted). No match writes nothing
  rather than guessing. Most countries render no state field at all, and the alternative to
  appending is losing the region.
- **`address2` and `state` are therefore added to `MIRRORED_ADDRESS_FIELDS` and to
  `Twopayment::MIRROR_WRITE_SESSION_KEYS`, and the pin now judges them.** This is the point Doug
  made when an earlier draft proposed leaving them out: *"it's just another element of the address;
  if a buyer specifies that, then yeah it should be pinned same as if the buyer entered a city."*
  The address-wide rule is "any field the buyer has entered pins the address", so a tracked set
  missing two writable fields would have made the pin miss real buyer-entered data. Widening it does
  mean a buyer-typed second address line now freezes the secondary address where it previously did
  not — that is the intended consequence, not a side effect.

**A `TWO:`-prefixed identifier is ordinary data (Doug's ruling), with ONE platform-forced exception.**
In Doug's words: *"why are you treating a sole trader number as any different from a registered
company's org number? You should handle, store and route them exactly the same as each other."* It is
not even a sole-trader concept — registered companies in some countries (the US among them) carry one
too. And on stripping the prefix to make it fit: *"we cannot strip the TWO: prefix because it is an
integral part of the company number. Without it, the number is meaningless to the API."*

So it is stored, paired, mirrored, routed and submitted through exactly the same code path as any
other organisation number, byte-identical, prefix intact — **except that it is never written into the
visible `dni` address field.** That exception is forced by the platform, not chosen:

- **Core rejects it.** `Address` declares `'dni' => ['validate' => 'isDniLite', 'size' => 16]` and
  `Validate::isDniLite()` is `/^[0-9A-Za-z-.]{1,16}$/U`. `TWO:ST123456789012` fails twice — a colon
  is not in the character class, and it is 18 characters. Writing it makes core **refuse to save the
  address**, and an earlier round paired that with hiding the field, producing an invisible and
  unfixable dead-end at checkout.
- **It is unreadable there anyway.** `extractOrgNumberFromAddress()` validates `dni` against
  `/^[A-Z0-9\-]{5,20}$/i`, which also rejects the colon. The value could never be read back, so
  persisting it there achieved nothing even when it did not break the save.
- **It is not our field.** `Country::isNeedDniByCountryId()` is country-level, so `dni` is required of
  *every* buyer in such a country. It is their own fiscal number (NIF/CIF). Leaving it alone blocks
  nobody — the buyer fills it as they always must — which is why this exception costs nothing while
  both alternatives (writing an invalid value, or leaving a hidden required field empty) blocked
  checkout outright.

**There is consequently no display rule on that field, and that is a deletion rather than an
omission.** An earlier round added `syncInternalIdentifierVisibility()` to hide it. With the value no
longer reaching it there is nothing to hide — and the hiding could never have been complete, because
PrestaShop renders `dni` into address blocks, invoice PDFs and order emails through
`AddressFormat::generateAddress()`, which no stylesheet reaches. The three genuine display surfaces
(the hint under the company field, the search result rows, the order-intent sentence) continue to
suppress the value through `TwoCompanyNumber.forDisplay()`, which is where a display rule belongs.

**Where the value DOES live:** the hidden `companyid` input (JS-created, no validation, not a
column), the cart-scoped session record, the in-memory `$address->companyid` the order-intent
controller sets, the order-scoped record described below, and the API payload. The prefix survives all
of them unchanged.

**The earlier round that refused the write for its own sake is superseded**, and the distinction
matters: that round also withheld the pairing and the name, and every defect that followed came from
*that* divergence — a mismatched name/number pair in the invoice form, the "name and number travel
together" invariant broken, and the required-field dead-end above. Here the hidden pair, its tag, the
session record, the mirror and the routing all stay completely uniform. Only the buyer's own fiscal
field is left alone.

**The billing organisation number is persisted on the ORDER, keyed by order id.** Two columns on
`ps_twopayment` — the module's own order-keyed table. No core table altered, no class override, per
`createTwoTables()`'s standing rule.

Why it is needed: `getTwoUpdateOrderData()` runs in admin/webhook context after placement, when core
has rotated the cart, so the cart-scoped session company is gone and the resolver falls to
`ps_address.dni` — empty for an internal identifier and empty entirely on most countries. So
`hookActionOrderEdited` and `hookActionAdminOrdersTrackingNumberUpdate` were PUTting an empty
organisation number. **That was the status quo for every buyer on any country without
`need_identification_number`, not only for sole traders**, so this is a broader fix than the ticket.

**It is the pattern that method already uses.** `two_day_on_invoice` is persisted and read back as
`$storedTerm` for precisely this reason, with the comment *"the update path runs in admin/webhook
context with no buyer term cookie… otherwise the fee would be recomputed"*. Same problem, same shape,
same method. This is not a new mechanism.

Design constraints, all load-bearing:

- **The value is the BILLING/INVOICE address's**, per Doug: *"the company number we want to persist is
  the same as the one that drives the intent and the order: the one associated with billing/invoice
  address."* Captured via `getTwoCheckoutCompanyData(new Address($cart->id_address_invoice))`, a
  fail-soft wrapper over the very resolver that builds the payload — not a second resolution path, and
  not the raw submitted field. Fail-soft matters here: a throw inside order confirmation would cost the
  buyer an already-approved order.
- **Captured in the same request as order creation**, while the cart-scoped record is still readable.
  Later is too late: a rotated cart reports absent *and clears the record on the way out*.
- **Writes are presence-conditional.** Ten of the eleven `setTwoOrderPaymentData()` call sites are
  status/webhook updates that know nothing about the company. An absent key means "unchanged", never
  "overwrite with empty" — otherwise the next status change silently erases the value. Same
  absent-means-unchanged discipline as the mirror-write record.
- **Reads prefer the stored value and fall back to live resolution when empty**, so orders placed
  before this release behave exactly as they do now. No backfill, deliberately: the value is only
  knowable from the buyer's own request.
- **Keyed by ORDER id, not address id.** `CustomerAddressPersister::save()` clones an address to a new
  row and soft-deletes the old one whenever it is edited after being used on an order, which would
  orphan anything keyed by the old address id. An order id is stable for the life of the order. This is
  the specific risk that ruled out the address-scoped variant.

Rejected alternatives, with the reasons, so they are not revisited blind: a column on `ps_address`
would need an `Address` class override, and this repo's override machinery (`TwoOverrideMigrator`, its
CI gate) never installs a NEW override on an existing shop — `Module::runUpgradeModule()` does not call
`installOverrides()`, the migrator returns early when nothing was removed, and the CI check only
inspects modified and deleted overrides. It would also silently no-op on any shop where another module
co-owns `override/classes/Address.php`, which the migrator deliberately refuses to touch. A module table
keyed by `id_address` avoids the override but hits the clone-and-re-key path above.

**A failed `ALTER` must never fail the payment row.** `ensureTwoOrderCompanyColumns()` returns the
columns it could actually guarantee, and the caller writes only those. The ordering matters: ask
first, then stage. A shop can legitimately reach the failure path — files swapped without the upgrade
script, or a database user with no `ALTER` privilege — and naming a nonexistent column fails the
ENTIRE insert. That row carries `two_order_id`, the invoice URL and everything later status syncs key
on, and the write happens inside the confirmation callback for an order Two has already approved.
Losing the snapshot costs an empty organisation number on two admin PUTs; losing the row costs the
buyer their order. The degradation is therefore exactly the pre-TWO-40 behaviour. Found by review
round 4 — the first implementation logged the failure and wrote the column anyway.

**Two known residuals on the `dni` path, recorded rather than patched.** Both exist only because a
`TWO:` number no longer reaches `dni`, and both are mitigated server-side because the cart-scoped
session record is resolver priority 1:

- On a `need_identification_number` country, the moment the buyer types their own NIF into `dni`,
  `completeMirroredOrganizationNumber()`'s "the field is theirs, the debt is settled" branch clears
  the last surviving record of the pending pair — so `republishMirroredSelection()` stops restoring
  the hidden `companyid` after a country-change rebuild, and the submit-time sync can then adopt the
  buyer's PERSONAL fiscal number as the organisation number. The underlying "`dni` is adopted as the
  org number" behaviour pre-dates this work and applies to every buyer on such a country; what is new
  is only that an internal identifier can never discharge the debt. Closing it means exempting an
  internal identifier from that settle. Left alone deliberately: four review rounds on this state
  machine have each produced defects of their own, and the order is protected by the session record
  and now by the order-scoped snapshot.
- `getTwoUpdateOrderData()` prefers the STORED company name as well as the stored number, so an admin
  editing the invoice address's company after placement no longer propagates it on
  `hookActionOrderEdited`. That is the intended trade and not an oversight: the stored pair is the
  identity the credit decision was made against, and sending a freshly-typed name beside the original
  number would silently re-label a funded invoice. Preferring one and re-resolving the other would
  pair a new name with an old number, which is worse than either.

**The address-wide pin is deliberately NOT consulted, and that is a REVERSAL of a review fix.**
Round 1 of the adversarial review added `secondaryAddressIsPinned()` as an early return, reasoning by
analogy with the invoice mirror. Round 2 showed the analogy is false, in two ways:

- `secondaryAddressFormRoot()` resolves non-null **only** when the invoice form is the VISIBLE,
  editable form. So the pin was gating the form the buyer is looking at and has just acted on - the
  exact opposite of what the pin exists for.
- An invoice form that core pre-filled from a saved address carries street, postcode and city with
  nothing on record as having written them. That reads as buyer-authored, so the address is pinned
  **by default**, and the adoption wrote nothing at all for every buyer editing an existing billing
  address. That is the reported bug reinstated.

The mirror's pin is right for the mirror because the mirror is a cross-page-load carry-over into a
form the buyer never asked it to touch. This runs from an enrolment the buyer has just completed on
the form in front of them, which is the one case the pin was never meant to cover. Recorded here
because the fix looks obviously correct in isolation and was applied once already.

**Alternatives Considered**:
- Leaving it as it was, per the 2026-08-10 decision. Rejected: it makes the flow pointless. The
  buyer completes a signup and sees an empty form.
- Hand-rolling the writes inside `TwoSoleTrader`. Rejected - this is exactly what the three
  withdrawn attempts did.
- Listening for `two:sole-trader-ready` in `TwoCompanySearch` and writing from there. Rejected: an
  event with no payload and one publisher is indirection with no second consumer to justify it, and
  the ordering against `publishConfirmedSelection()` matters (see below).

**Rationale**:
- **The 2026-08-10 blocker is real and is closed by the pairing tag, not worked around.** That
  entry correctly identified `clearStaleOrganizationSelection()` reading company-set /
  number-set / tag-absent as "the buyer has edited past a stale selection" and wiping the write on
  their next keystroke. Every withdrawn attempt wrote an UNTAGGED `companyid`. PR #157 landed
  `markOrganizationFieldSelected()`, which sets the field and its `data-two-company-name` tag
  together as one operation; a write through it presents a VALID pairing, and the guard leaves it
  alone. The guard itself is untouched - a buyer who genuinely retypes a different name still
  clears the selection.
- **Every write goes through the writer a real search selection already uses.** No new write path,
  no new marker vocabulary, no new scope resolution. The address fill reuses `autoFillAddress()`
  with the same three-state scope logic `autoFillAddressIfNeeded()` applies, and reports through
  `recordMirrorWrites()` when the form written into is the secondary address - so the plugin's own
  values are recognised as its own on the next render instead of pinning the address.
- **The tag must match what is IN the company field, not the label posted to the server.**
  `applyBuyer()` falls back to the organisation number when `company_name` is blank, and that
  fallback is where the synthetic `TWO:` identifier appears. Tagging with that label while the
  visible field holds something else would have the guard wipe the pair just as surely as no tag.
- **The company-name write is unconditional, unlike the invoice mirror's.** The mirror writes into
  an address the buyer is not looking at, so it must not overwrite their answer. This runs off a
  signup the buyer has just completed in front of them, and the company field on the payment tile
  IS the search box - a writability rule would refuse the one case the flow exists for: a buyer who
  typed a name, found nothing, and enrolled instead.
- **Order matters: adopt before publishing.** `setConfirmedCompanySelection()` re-derives the
  captured address from the CURRENT page, so it has to see the written values rather than the empty
  form they replaced.
- **Works on both mounts.** The address-editor page renders no `.two-sole-trader` container, so
  anything gated on one silently no-ops there - the same trap the TWO-40 follow-up in
  `getCurrentBuyer()` fixed. Only status/prompt UI stays container-scoped, which is right: there is
  nothing to show where there is no container.
- **Fails soft.** The search instance is resolved lazily at call time (the manager destroys and
  rebuilds it on every `updatedAddressForm`, and the enrolment spans a popup round trip). A missing
  instance costs the fill, never the enrolment - the identity still reaches the order through the
  session record and the published selection.

**Consequences**:
- **A blank `company_name` writes no identity at all** - no company name, no hidden `companyid`, no
  `dni`. The name is what makes the pair writable: tagging the number with whatever the buyer last
  typed is a mismatched pairing that makes `hasConfirmedSelection()` lie, and tagging it with the
  empty string has the stale-pairing guard wipe it on the next input event. The address still fills,
  because an address fill carries no pairing. The order is unaffected either way.

  It does, however, **disown any selection that was standing before the buyer enrolled** - the
  hidden pair, its tag, the company-id hint and the lookup-written `dni`. Without that, the session
  and the manager would say sole trader while the form still said the abandoned company, and the
  resolver's address tier reads the form. Cleared field by field and deliberately **not** through
  `clearSelectedCompany()`, which also POSTs a clear of the server session company - the record
  `saveCompany` had written for this enrolment moments earlier, asynchronously; that clear would land
  after it and destroy the enrolment. (This is the exact trap the three withdrawn attempts kept
  falling into: the obvious fix reaching further than intended.)

  **Residual, needing a ruling:** the buyer-visible company field is left holding whatever it held.
  Clearing it means deciding what a nameless sole trader's company field should say, and the two
  available answers are showing an internal identifier the buyer must never see, or inventing a name
  from their personal name.

- **Known shared residual, deliberately not closed here: the scope-resolution asymmetry.** Where the
  invoice form is on screen but `visibleAddressFormRoot()` fails to scope it - a theme that flattens
  both addresses into one block - the address fill is SKIPPED (no scope, no write) but the company and
  `dni` writes still go document-wide. That asymmetry is inherited rather than introduced: this
  adoption is a faithful copy of `autoFillAddressIfNeeded()`, whose identity writes are ungated in
  exactly the same way, and whose address fill fails closed in exactly the same way. Gating only the
  new path would leave the two capture routes disagreeing about the same page. Closing it properly
  means changing the ordinary company-selection path too, which is its own piece of work.

- **Two behaviours review raised that are NOT closed here, both needing a ruling.** (1) A country
  change during the signup popup round trip does not bump `_enrollGeneration`, so the adoption can
  write an identity minted against the previous country into a form now on a new one; the server will
  discard the session company on that divergence.
- With the address-lookup switch off, the address fields and `dni` are not written; the company
  name and hidden pairing still are. That is the existing meaning of that switch, applied here
  unchanged.

**A country change wiping a SEARCH or SOLE-TRADER capture is correct and must keep working** (Doug:
*"the ONLY time that a country change should not wipe company details is if the control is in manual
entry mode"*). It currently does — `setupCountryChangeListener()`'s handler blanks the company field
and runs `clearSelectedCompany()`, which drops the hidden pair, the tag, the marked `dni` and the
session company. So the earlier proposal to restore a sole-trader pair after a country rebuild via
`republishMirroredSelection()` was **wrong and is not implemented**; it would have defeated the
intended wipe.

**MANUAL-ENTRY mode is the real bug there, and it is NOT fixed in this piece of work.** Investigation
confirmed that a country change wipes a hand-typed company name too: the country handler blanks the
field and clears the selection with no mode check, and `_manualEntry` lives on the search instance,
which the manager destroys and rebuilds on every `updatedAddressForm` — so the mode itself does not
survive either, and the fresh instance comes back in read-only search mode. The end state is
half-wiped and inconsistent: the typed name gone, the session cleared, but an unmarked hand-typed
`dni` left behind, which the submit-time sync then adopts as the organisation number for whatever
name is in the field by then. Fixing it needs both a mode guard on the wipe and `_manualEntry`
persisted off-instance the way `mirrorMemory()` already is. That is a change to the manager's
instance lifecycle, unrelated to the sole-trader autofill, and belongs in its own PR rather than
widening this one. No test anywhere currently fires a country-select `change` while in manual entry.

---

## [2026-08-10] Sole-Trader Enrolment Does Not Write Back Into The Address Form

> **SUPERSEDED by the 2026-08-11 entry above.** The write-back now exists. One bullet below is
> also factually wrong and is corrected in place - see the marked line.

**Context**: A completed sole-trader enrolment leaves the address form untouched (TWO-40). Adopting the enrolled identity into that form was attempted three times - first the trading name into the visible `company` field plus a publish and a cookie write as a backstop, then the same without the cookie write, then the organisation number alone mirrored into the address `dni` field - and withdrawn each time. The delivered fix is the token-mint precondition change only.

**Decision**: No write-back at all. `two:sole-trader-ready` stays a bare notification with no payload, and `TwoCompanySearch` does not listen for it.

**Alternatives Considered**:
- Name + number adoption with a publish and cookie backstop (implemented, withdrawn)
- The same without the cookie write (implemented, withdrawn)
- Organisation number alone, through the already-gated `writeOrganizationToAddressIdentifiers()` writer (implemented, withdrawn)
- ~~A true street/postcode/city autofill from the buyer-autofill response - not possible as that endpoint is consumed today, because the response carries no address payload at all; it needs an API contract confirmation before it can be designed~~ **WRONG, corrected 2026-08-11.** The response carries a full `billing_address` object (street, city, postal_code, building, apartment, region) plus `country_code`. No contract confirmation was needed. See the 2026-08-11 entry for the captured payload; the autofill is now implemented.

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

# company-search / sole-trader restructuring (TWO-40)

**Status, per item — the design below is NO LONGER uniformly unimplemented:**
`#6`/`#9` **SHIPPED** (see its own "as built"), `#12` **SHIPPED**, `#13` **SHIPPED**
(read side needed no code; write side shipped in a different shape — read the
corrections section first), `#8` **DEFERRED by Doug, deliberately not built**, `#1`
**WITHDRAWN**. Each item's own "as built" subsection is the authority on what
actually exists; the proposals above them are kept for their reasoning, not as a
description of the code.

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

## Corrections to this document's premises, verified against PrestaShop core

Extracted from the official `1.7.8.11`, `8-apache` and `9-apache` images —
`themes/classic/templates/checkout/_partials/steps/addresses.tpl`,
`checkout/_partials/address-form.tpl`, `_partials/form-fields.tpl` and
`classes/checkout/CheckoutAddressesStep.php`. The two checkout templates are
byte-identical between 8 and 9; 1.7.8.11 differs only by one extra attribute and
a hook, so all three supported majors behave the same here. **Where the designs
below disagree with this section, this section wins** — they were written on a
wrong premise.

**A. PrestaShop NEVER renders two editable address forms at once.** The delivery
and invoice form flags are set in mutually exclusive branches of
`CheckoutAddressesStep::handleRequest()` and of the address-count block that
follows it. The other side is always a radio selector over saved addresses, or
absent. So `#13`-enabled's mirror as designed — copy company + country from the
block the search ran in into the billing block — is **not implementable**: when
the delivery form is on screen there are no invoice inputs to write into. The
mirror has to be a **cross-page-load** operation, seeding the invoice form when it
later becomes the editable one. As a corollary, Doug's "no silent population of a
hidden block" is satisfied for free: there is no hidden block. The comment on
`TwoCheckoutManager.neutralizeCompanySearchAffordance()` claiming a second
`name='company'` input appears once the buyer states the addresses differ was
wrong, and is the premise this document inherited; it has been corrected in place.

**B. There is no "addresses differ" checkbox to listen to.** `use_same_address` is
a server-side Smarty flag expressed by two different controls.
`address-form.tpl` renders the checkbox **only** under `{if $type ===
"delivery"}`, i.e. only while the delivery form is the editable one, and its
polarity is **checked = the addresses are the SAME**. On every later pass core
renders a LINK instead (`data-link-action="different-invoice-address"`), which is
an `href` performing a full page navigation. So the reveal is a page load and
there is no client-side event to hook, which is why the mirror has no reactivity
half.

**C. `TwoCountry.js` must NOT be extracted, and was not.** The design called it a
prerequisite of `#13`. It is not one: because only one address form is ever
rendered, there is only ever ONE `select[name='id_country'], select[name='country']`
on the page, so the four resolvers' first-match-in-document read cannot pick the
wrong select. All four were checked and all four already prefer the live select
when one exists. Dropped rather than deferred — the reason it was wanted has been
removed, not postponed. The duplicated country-name→ISO maps are untouched and
remain Doug's separate item.

**D. `window.twopayment.billing_country` was already the right source.**
`Twopayment::getCheckoutBillingCountryIso()` derives it from the cart's
`id_address_invoice`, so it is the INVOICE address's country, not the delivery
one. Verified, left alone, and now pinned by a spec asserting exactly that — with
both addresses set to different countries, so the assertion identifies which field
was read. Nothing guarded it before, and it is the terminal fallback for every
country resolver in the checkout JS.

---

## #6 / #9 — why does the company selection persist at all, and for an hour?

### What exists today

Four cookie keys, all written server-side, all sharing one expiry:

| key | written at |
|---|---|
| `two_company_name` | `orderintent.php`'s `ajaxProcessSaveCompany()` and `storeCompanyDataInSession()`, `twopayment.php`'s `hookActionCustomerAddressSave()` |
| `two_company_id` | `ajaxProcessSaveCompany()`, `storeCompanyDataInSession()` |
| `two_company_country` | `ajaxProcessSaveCompany()`, `storeCompanyDataInSession()` |
| `two_company_address_id` | `ajaxProcessSaveCompany()`, `storeCompanyDataInSession()` |

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
   it outright when `cart_id !== $this->context->cart->id`. An ordered cart is never carried again
   — core's front-controller init unsets the cookie's cart id once `Cart::orderExists()` is true and
   assigns a fresh cart — so a selection cannot survive into a future order even if the cookie
   physically outlives the checkout. This is the single change that delivers Doug's requirement, and it is strictly
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

**Resolved:** unreadable post-placement is fine. The selection is only needed up to the point of
order placement and nothing further, so no additional lifetime handling is needed.

### As built — and where the proposal above was wrong

Cart-scoping shipped. Steps 1, 2 and 4 above did **not**, and should not be revived from this
document without re-deciding them:

- **Step 1 (one `two_company` blob) — not done.** A single new key, `two_company_cart_id`, is written
  alongside the existing four instead. Restructuring the record would have rewritten the shape every
  read site consumes for no gain the requirement asks for. The keys are centralised in
  `Twopayment::COMPANY_SESSION_KEYS` so they cannot drift, which is what the blob was really for.
- **Step 2 (drop the company writes' `setExpire`) — not done, and it was wrong.** PrestaShop's cookie
  has one expiry for the whole cookie, not one per key: `Cookie::setExpire()` assigns the single
  expiry scalar that the cookie's own `setcookie()` call uses. So removing those calls would not
  shorten the company record's life at all — it would hand the *whole* cookie's lifetime back to
  whatever core's front-office config computes from `PS_COOKIE_LIFETIME_FO`, which is shop
  configuration (a positive value means that many hours; `0` means a cookie that dies with the
  browser session), i.e. possibly longer *or* shorter than an hour, and it would re-time every other
  key sharing the cookie as a side effect. That side effect is the whole reason on its own — despite
  what earlier revisions of this file and the code comments claimed, **no other key actually depends
  on the hour**: the API-key verification verdict is cached in `Configuration` (the database), not the
  cookie; the order-intent rate limiter is bounded by its own 60-second window over the timestamps it
  stores; and the order-intent decision flag is bounded by `ORDER_INTENT_DECISION_CACHE_TTL`. Every
  `setExpire` call and `COOKIE_EXPIRY_ONE_HOUR` are untouched. Cart-scoping is the entire fix.
- **Step 3 (keep both invalidation guards) — done.** The country-mismatch wipe and the
  no-country-marker wipe are unchanged and have their own regression cover, because a buyer can change
  address country inside one cart.
- **Step 4 (drop the unverified-cookie name tier) — not done, deliberately.** It is not a duplicate
  read. It fires when the validated read declined for a reason other than an address switch, and it
  re-reads *after* the guards have had their chance to clear, so it observes post-clear state rather
  than a snapshot. Removing it would lose the company name on the path where a stored name has no
  organisation number beside it and the address carries no company.

Shape as built, all on `Twopayment` and reached from the front controller via `$this->module`:
`storeTwoCartScopedCompany()` (stamps the current cart alongside whatever fields it is given; a field
passed as null is removed; an unrecognised field name is logged rather than skipped in silence),
`readTwoCartScopedCompany()` (returns the record only when the stamp equals the current cart id,
otherwise clears and reports absent), `clearTwoCartScopedCompany()`.

With **no loaded cart the writer writes nothing and clears nothing.** A record it could only stamp `0`
would be unreadable by construction — the reader matches the stamp against the current cart id, which
is always greater than zero — and the next read that *did* have a cart would clear it as belonging to
another cart, destroying whatever was already there. Declining is also what the reader's own no-cart
policy promises. The reachable caller is `hookActionCustomerAddressSave()` on the My-Account address
page, where the buyer need not have a cart at all.

A cookie written before this change carries no stamp, so it reads as absent and is cleared. That is
intended — there is no migration, and the whole cost is that the buyer re-picks their company.

Also removed as part of this: `TwoCheckoutManager.isCompanyDataMissing()`'s
`document.cookie.match(/two_company_id=.../)` fallback. Nothing ever wrote a browser cookie of that
name — PrestaShop serialises server-side session keys into one encrypted cookie under its own name,
and no code sets one directly — so the fallback could only ever be satisfied by a test that
fabricated it, which one Jest spec did: `tests/js/company-search-tile-mode.test.js`, which now drives
the real carrier, the hidden `input[name='companyid']`. A second spec carried only an `afterEach` wipe
of a cookie it never wrote, and that line is simply deleted.

---

## #8 — DEFERRED, not pending — tile-mode's address-side layer is gated on the wrong thing

**Status: considered and explicitly deferred by Doug (2026-08-11). Nothing below
is being worked on, and the design was NOT implemented.** His ruling: there are no
plans at present to let the admin enable address population while the company
search is in the payment tile, so the current no-op is fine. The tile-mode
inertness stays exactly as it is — address-field writes force-disabled from the
mount location, `isAddressLookupSettingAvailable()` retained, the tile mount's
hardcoded `addressLookupEnabled: false` retained.

Recorded rather than deleted so that the analysis is not re-derived from scratch,
and marked deferred so it is not re-investigated as though it were outstanding
work. Revive it only if an admin path actually needs the two questions separated;
note in that case that step 4 (root-scoping the address writes) is the load-bearing
part and cannot be skipped — though `#13`-enabled's mirror already scopes its own
writes to the visible form element, so there is a working precedent for it now.

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

**SHIPPED as proposed. See "#12 — as built" at the end of this item.**

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

### As built

**Shipped, all five points, exactly as proposed.** The posted country is tier 1,
the invoice-address tier is deleted rather than demoted, the cart's delivery
address is the sole last resort, both docblocks were rewritten rather than left
arguing for the old ordering, and the shape check is byte-for-byte unchanged.

The three claims the docblock now makes about there being no privilege to escalate
were each re-verified in the code before being written down, not carried over from
the previous comment: `TwoSoleTrader::mintTokens()` takes only a module and both
delegation payloads it posts are fixed scope lists with no country; the registry
availability check runs on every call inside the token action; and
`ajaxProcessSoleTraderAvailability()` answers a client-supplied country by design,
with a docblock of its own saying so.

Spec changes: the two invoice-address-first cases are replaced by their mirrors
(a posted country beats a committed invoice address; a cart carrying only an
eligible invoice address mints nothing, which is the proof the tier was deleted
and not demoted), the two address-resolution-failure cases moved onto the delivery
tier, and `testPostedCountryCannotConjureAvailability` is unchanged and is now the
more load-bearing of the pair.

**The coupling to `#13`, and the trade-off it leaves — accepted, not absent**
(corrected in round 5; an earlier revision of this paragraph said "which select" was
"never a question on PrestaShop", which is true but incomplete, and reads as a claim
that nothing can go wrong here). Per correction C there is only ever ONE country
select on the page, so the browser cannot post the wrong one. But *which* address
that one select belongs to depends on which pass the buyer is on: on the
delivery-address-editing pass it is the DELIVERY country. So a buyer whose billing
address differs from their shipping address, clicking the sole-trader chip while
editing delivery, is now gated against their **shipping** country rather than their
real billing country. That is a genuine behaviour change from before this PR, where
the committed invoice address was tier 1.

It is accepted under Doug's explicit ruling that the live in-page value wins, and it
has **no security consequence** — minting takes no country parameter at all
(re-verified: `TwoSoleTrader::mintTokens($module)`), so the country only selects
which registry answer the availability gate reads, and the browser can already ask
for any country's answer through `soleTraderAvailability` by design. Recorded here as
a trade-off precisely so it is not "fixed" back into an invoice-address tier later:
reintroducing that tier is the stale-value bug this item removed.

---

## #13 — two distinct modes: ENABLED writes, DISABLED reads

**SHIPPED, but not in this shape.** Corrections A, B and C at the top of this
section invalidate three of the proposals below — the selection-time mirror, the
reveal listener, and the `TwoCountry.js` prerequisite. Jump to "#13 — as built" for
what exists; the contract table immediately below is still accurate and is the
requirement the built version satisfies.

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

### #13 — as built, and where the design above was wrong

Read corrections A–D at the top of this section first; three of them rewrite this
item.

**The READ side (disabled mode) needed no code at all.** All four country
resolvers — `TwoCompanySearch.getCurrentCountry()`,
`TwoSoleTrader.billingCountry()`, `TwoOrderIntent.getCurrentAddressCountryISO()`
and `TwoCheckoutManager.getSelectedCountryIso()` — were checked and every one
already prefers the live select over its own terminal fallback. Because only one
address form is ever rendered there is only one select to find, so the
"which select do I read" decision the design worried about does not exist here, and
the `TwoCountry.js` extraction it wanted as a prerequisite is dropped (correction
C). What was missing was a guard on the fallback's *source*, so a spec now pins
that `window.twopayment.billing_country` comes from the cart's invoice address and
never its delivery one (correction D). No resolver was modified.

**The WRITE side (enabled mode) shipped as a cross-page-load mirror**, because
correction A makes the designed selection-time mirror unimplementable. It runs when
the company-search control mounts, not on selection, and not on a reveal event —
there is none (correction B).

Shape as built, all on `TwoCompanySearch`:

- `buyerStatesInvoiceAddressDiffers()` — the signal. Resolution order: the
  shared-address control's NEGATION when that control is in the DOM at all; else the
  presence of an invoice block of either shape (form or selector); else **false**,
  i.e. no-op, because an unclear signal is not evidence the addresses differ. It
  carries a comment naming PrestaShop's polarity (checked = SAME) and the fact that
  another platform in this family inverts it, as the reason the abstraction exists
  at all. **Language convention, and it is a code requirement rather than a
  reporting one: "when the buyer states" / "when the buyer's current selection
  indicates" throughout — never "ticked" or "checked".** Doug's reason: the checkbox
  appears on the first pass only and another platform inverts its polarity, so
  checkbox language actively misleads whoever ports this.
- `visibleAddressFormType()` — which address the one editable form is for, read from
  the hidden field core's address form emits carrying exactly that word.
- `visibleAddressFormRoot()` — the scope for every field lookup. Innermost-first,
  and it resolves to the block element rather than the form, because core nests the
  rendered address form's own `<form>` inside the step's outer one and HTML drops
  the inner tag.
- `mirrorConfirmedCompanyToInvoiceAddress()` — the mirror. It is THREE operations and
  they must stay three: `reapplyMirrorMarkers()` re-establishes the autofill marker on
  a value still exactly what the mirror recorded writing (never writes a value,
  never touches an empty field),
  `populateInvoiceAddressFromConfirmedCompany()` fills unanswered fields at most
  once per company per page, and `completeMirroredOrganizationNumber()` places the
  organisation number when core's rebuild is what separated it from the name (see
  below). Supporting parts: `mirrorTargetIsWritable()`,
  `writeMirroredValue()`, `serverRenderedSelectValue()`, `mirrorCountryIntoForm()`,
  `countryOptionValueForIso()`, `mirrorMemory()`. Company name, its organisation
  number and the country; gated on the merchant's address-population switch as the
  design required, which also makes it inert on the tile mount for free.

Four things worth knowing before touching it:

- **"Empty" is NOT a reachable state for the country select, and a rule written
  around emptiness makes the country half dead code.** Core's `form-fields.tpl`
  emits the disabled, empty-valued "Please choose" option as `selected` ALWAYS, and
  also marks the option matching the field's value — which
  `CustomerAddressFormatter` sets unconditionally to the address's country id. Two
  selected options, last one wins, so `select.value` on a fresh unanswered form is
  the rendered country id and never `''`. "Unanswered" therefore means **still
  exactly the value the server rendered**, read from the `selected` attribute. An
  earlier revision of this section claimed the opposite; it was wrong, and a Jest
  fixture that marked only the placeholder is what hid it.
- **The name and its organisation number travel together, or neither travels —
  with one standing exception, and one the code now closes.** Once the address is
  saved, the resolver can reach the tier that reads the company off the ADDRESS, so
  a mirrored name with no number beside it is an order carrying a company the buyer
  never typed and no organisation number at all. A form whose identification field
  already holds the buyer's own number therefore gets neither write.
  - **The standing exception:** a form with no identification field at all still
    gets the name. Its presence is decided by the country's address format —
    `AddressFormat::getFormat()` appends `dni` only for a country flagged
    `need_identification_number`, which on stock data is ES and MX alone — so on
    most countries there is nowhere to put a number, and the ordinary company lookup
    has always behaved this way there.
  - **The exception the rebuild used to open, now closed:** because the field's
    presence follows the COUNTRY, the mirror's own country write can change which
    fields exist. Mirroring into ES from a country without the field gave back a
    form with an empty, REQUIRED identification field that the once-per-company
    populate gate then forbade ever filling; the reverse direction lost a number
    already written, since core's INPUT-only restore loop cannot restore a field the
    new render does not emit. So the two halves are recorded SEPARATELY — a number
    the mirror has PLACED in a field versus one it still OWES — and a third
    operation, `completeMirroredOrganizationNumber()`, places the owed half when a
    field for it appears. It is gated on the **marked name**, never on the number
    field being empty: "empty" is the very test the populate gate exists to refuse,
    and only a number the mirror never placed anywhere may be completed, into a
    field carrying no marker of any kind. A number the mirror wrote and the buyer
    then cleared is not owed, and no COMPLETION refills it **on a form that kept its
    identification field** — which is the case the gate is about.
  - **That qualification is not the whole story, and there is a SECOND route**
    (round 6). It is not enough to scope the promise to the completion path: since
    the mirror publishes the selection through the hidden `companyid` field the way a
    real selection does, the submit handler's
    `syncOrganizationToAddressIdentifiers()` reads that field and writes its value
    into the identification field on the way out — same country, no rebuild, no round
    trip, on the very next submit. So a cleared number comes back by either of two
    routes: the re-mark/complete pair after a country change, and the pre-submit sync
    with no country change at all. The second is deliberately left alone rather than
    fixed: it is precisely what a real selection does on that same form, and the
    alternative is offering the order a company name with no organisation number
    beside it. Recorded because these two sentences exist to be accurate about what
    the code guarantees, and a qualification that is merely narrower than the old
    absolute is still false.
  - **The residual, stated rather than claimed away** (round 5): the re-mark path
    re-pends a placed number when the rebuild renders a country whose format has NO
    identification field, and it does so from the field being ABSENT, without
    consulting the value it held. So a number the buyer cleared themselves, followed
    by a trip to a country without the field and back, IS owed again and IS
    refilled. Accepted, and narrow by construction: the field is only ever absent on
    countries that do not require it and only ever pending-completed on countries
    that do, and core rejects an empty required identification number at save
    (`CustomerAddressFormatter` marks it required, `AbstractForm` errors on an empty
    required field, and `Address` rejects it independently) — so the cleared state
    the buyer is "losing" is one they could not have submitted. The absolute wording
    that used to stand here, and its copy in `CHANGELOG.md`, described a guarantee
    the code does not make; `completeMirroredOrganizationNumber()`'s own docblock
    always described the residual correctly.
- **A successful country write triggers core's own form rebuild**, which is why the
  re-mark operation exists: core's `.js-country` handler is delegated on `body`,
  POSTs `action=addressForm`, replaces every `.js-address-form`, and restores the
  previous values with an INPUT-only, VALUE-only loop. Values survive; the
  `data-two-autofilled-value` marker does not. The mirror's record of what it wrote
  lives on `TwoCheckoutManager`, not on the search, because the manager destroys and
  rebuilds the search on every `updatedAddressForm`.
- **The selection cannot come from `TwoCheckoutManager._confirmedCompanySelection`
  alone** — it is page-lifetime and the mirror exists to cross a navigation. The
  manager now seeds it from the cart-scoped record the module publishes into the JS
  config. That publish asks the same three questions the validated read asks — the
  company/number pair, the country agreement, the captured address — but is
  **read-only**: it runs from the setMedia hook on every checkout render, so acting
  on a rejection there would mean merely drawing a page destroys the buyer's
  selection whenever the cart's committed invoice address country disagreed with it.
  A rejected record is withheld and left for the consuming path to judge and clear. The getter is INJECTED into the
  search mount rather than reached for on `window`: the search is constructed from
  inside the manager's own constructor, before `TwoCheckoutManager_Instance` is
  assigned, so a global lookup would find nothing on the one call that matters.

**Not built, deliberately:** the reactivity half (no event to bind — correction B),
the `TwoCountry.js` extraction (correction C), and any change to
`autoFillAddress()`'s existing street/postcode/city contract. The mirror is a
separate write path alongside it, not a modification of it.

## Cross-cutting: what has to land together

Superseded by what actually shipped — kept because the reasoning is still worth
reading, but do not act on it:

- `#8` step 4 (root-scoping the address writes) was a **hard prerequisite** for
  `#8` step 3 and for `#13`-enabled. `#8` is deferred, and `#13`-enabled's mirror
  scopes its own writes to the visible form element rather than relying on that
  step, so it stands alone.
- `#12` and `#13`-disabled being two halves of one feature held, but the browser
  half turned out to need no change (correction C): the resolvers already read the
  live select, and there is only ever one of them to read.
- `TwoCountry.js` extraction: dropped, not deferred (correction C).
- `#6`/`#9` is independent of all of the above and shipped on its own.

## Open questions for Doug

1. **`#6`/`#9`:** may a placed order's company selection still be read after placement
   (confirmation page, admin)? Cart-scoping makes it unreadable and I have not audited that path.
2. **`#8`:** with the gate removed and the merchant switch on, tile-mode writes should silently
   no-op when the address form is not on the page. Confirm, rather than deferring the write.
   **Moot — `#8` is deferred; no admin path needs the separation yet.**
3. **`#13`-enabled:** the mirror copies the company **name, its organisation number and the
   country**. Confirm that street / postcode / city are deliberately excluded when the buyer has
   said the addresses differ.
   **Confirmed by Doug, and built that way.** The organisation number was added in review: without
   it the order could carry a company name the plugin itself wrote with no number beside it.
4. **`#13`-enabled:** when the buyer has NOT stated the addresses differ, there is one address and
   nothing to mirror. Confirm that is a no-op and not "populate a hidden billing block anyway".
   **Confirmed: a true no-op. It is also moot as a risk — per correction A there is no hidden
   block to populate.**
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

## A failed copy keeps the old row and reports it — it does NOT fail the upgrade

The one path with no good outcome: `Configuration::updateValue()` answers falsy or raises, so the value
never reaches the new key. The script keeps the old row there (it is then the only copy of the position
the merchant chose) and logs at severity 3 — **and still returns `true`**.

Returning `false` was weighed and rejected. It *would* make the script re-runnable: core captures the
return value, only a truthy one advances the `upgraded_to` it writes to `ps_module.version`, and the
version-gated discovery would therefore offer `upgrade-2.7.6.php` again on the next attempt. But before
that, core calls `disable()` on the module for a falsy return — which deletes its `module_shop` rows, so
the payment method leaves the storefront, and (because this module ships an `override/` directory) also
runs `uninstallOverrides()` and strips the module's overrides out of the shop's override tree. Trading a
working checkout for the automatic recovery of one config row is the wrong way round; the module's rule
that a shop which cannot be tidied must still finish upgrading holds here.

The consequence is that **the kept row is a record, not a remedy**: nothing re-runs the script (no
re-run, and Doug ruled out a read shim), so recovery is a human re-selecting the position on the
module's configuration page, or copying the value across in `ps_configuration`. The log message says
that and deliberately promises nothing automatic. Both write-failure shapes — falsy return and raised
exception — leave the same shop state and so are reported identically; the offline spec pins that, and
pins the `true` return on every path.

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

## Round 5 (independent adversarial review): what was fixed, and what was noted instead

Five reproducible defects, not findings. All of the below are re-derived against this
branch's HEAD, not carried over from the review's own line numbers.

- **The mirrored organisation number was invisible to the stale-selection guard.**
  The mirror wrote the address `dni` and its autofill marker but never the hidden
  `companyid` input or its `data-two-company-name` pairing tag — and
  `clearStaleOrganizationSelection()` reads `companyid` first and returns
  immediately when it is empty. So "the buyer retyped the company name over a
  selection" could never fire for a mirrored value: company A's organisation number
  shipped attached to a name the buyer typed afterwards, and neither the marked
  `dni` residue nor the cart-scoped server record was dropped, so the next address
  step re-mirrored the name the buyer had just cleared. Fixed by routing the mirror
  through the same browser-side publish path a real selection uses
  (`markOrganizationFieldSelected()`), NOT by special-casing the guard. Both writes
  go together and must: a non-empty `companyid` with no pairing tag reads to the
  guard as "the buyer has edited past a stale selection", so tagging is what stops
  the mirror destroying its own write on the `input` event it fires.
  - A FOURTH mirror operation came with it, `republishMirroredSelection()`. The
    hidden field lives inside `.js-address-form`, which core's country-change
    rebuild replaces wholesale — and the mirror's own country write is what triggers
    that rebuild — so without a re-publish every mirrored selection went blind again
    on the first country write. Gated on the marked name exactly as the completion
    is.
  - The mirror deliberately does NOT publish to `TwoCheckoutManager`.
    `setConfirmedCompanySelection()` re-derives the captured address and country
    from the current page, which is the very thing
    `seedConfirmedCompanySelectionFromServer()` exists to avoid.
- **A real API failure was reported as "you didn't pick a company", and in tile mode
  as nothing at all.** `isCompanyDataMissing()` read only the DOM `companyid` input,
  while the seed gave the page-lifetime holder a second legitimate claim to a real
  selection — decisive on the payment step, where PrestaShop has removed the address
  form. Misclassified failures then hit `suppressCompanyRelocationPrompt()`, which
  returns early, so the buyer saw an empty panel for a 500. It now consults
  `getConfirmedCompanySelection()` too.
- **The scope resolution's `closest('form')` fallback reintroduced the
  document-wide write.** A theme without core's block ids resolved to the step's
  OUTER form, which contains both address blocks. It now fails CLOSED: `form` is off
  the candidate list, and any candidate that CONTAINS another address block is
  rejected, so an unidentifiable scope means no mirror rather than a wide one. It
  was untested because every fixture carried `#invoice-address`;
  `buildAddressesStep({blockContainers: false})` now models the flattened theme.
- **Two doc absolutes were understating accepted trade-offs** — the `#12` sole-trader
  country coupling and the cleared-number residual. Both reworded above, in place,
  rather than annotated here.

### Noted, deliberately NOT fixed in this round

- **Selection state lives in roughly six places with independently-copied validity
  logic** — the hidden `companyid` input plus its pairing tag, the address
  identification field plus its autofill marker, the mirror's page-lifetime memory,
  `TwoCheckoutManager._confirmedCompanySelection`, the session cookie, and the
  server's cart-scoped record. Each carries its own notion of "is this selection
  usable", and that duplication is the root cause of the first defect above: the
  mirror satisfied one copy's rules and was invisible to another's. Consolidating
  them is a refactor with a wide blast radius and this PR is five rounds deep, so it
  is a follow-up, recorded here so it is not lost. The fix above deliberately reuses
  the existing publish path instead of adding a seventh place.
- **A theme with no `input[name='saveAddress']` gets a silent no-op with no log.**
  Left as-is rather than logged, because the cheap version is not cheap: the mirror
  exits on the same condition for the ordinary delivery pass, so a one-line log at
  that point would fire on every normal page. Telling "no marker at all" from "this
  is the delivery form" needs a branch of its own, which is more than this round is
  taking on.
- **The per-mirror-write server round-trip form rebuild** is by design — core owns
  the rebuild and the mirror's country write legitimately triggers it.
- **The cookie last-writer-wins race under concurrent AJAX** is pre-existing and
  unrelated; untouched.

---

# TWO-40 — the secondary address is editable, and syncs by content match

**Status: RULED and IMPLEMENTED on the TWO-40 address-split branch (PR #157).** It
supersedes the one-way write-once "invoice mirror" recorded in that branch's
`#13 — as built` section. Where this note and that section disagree, this note is
both the intent and the code.

Doug's original correction, restated as four requirements:

1. **Editable.** The buyer may edit company and country on the secondary address.
2. **Conditionally synced.** The secondary defaults to the primary's company and
   country, and keeps tracking it when either changes on the primary — UNLESS the
   secondary already holds information the buyer put there.
3. **Role-keyed read.** The payment tile reads country and company from whichever
   address plays the BILLING/INVOICE role, never from a fixed primary/secondary
   label.
4. **Role-keyed enforcement.** Company is required only on the address playing the
   billing/invoice role, and is optional on the shipping address.

Rules 3 and 4 must share ONE notion of "which address plays the billing role". Two
parallel notions is the defect this note exists to prevent, not a shortcut it may take.

---

## Doug's rulings, verbatim

**R1 — the pin is triggered by ANY address field, not just company/country.**

> "Unfortunately we need to pin it if any address field has been entered, not just
> country/company."

**R2 — country for the sole-trader flow splits by where the company search is
mounted.**

> "(a.1) and (a.2) resolve to the same rule: sole trader visibility (and country for
> input to workflow) is driven by the local country selection - ie for company search
> in shipping address, look at shipping country. (a.3) here, sole trader visibility
> and workflow should be driven explicitly by the billing / invoice address."

**R3 — no dedicated control; sync is driven by comparing field contents.**

> "No dedicated control. Trim the address lines and ignore case when comparing, but
> otherwise, sync is driven by a match on field contents."

---

## The platform constraint that rewrites rule 2

Doug's rules are written for a checkout where both address panels can be open at
once. PrestaShop is not that checkout, and the difference is not cosmetic.

Verified against the official 1.7.8.11, 8 and 9 images (the two checkout address
templates are byte-identical between 8 and 9; 1.7.8.11 differs by one attribute and
a hook):

- **Only ONE editable address form is ever on the page.** The delivery- and
  invoice-form flags are set in mutually exclusive branches of the addresses step's
  request handler and of the address-count block after it. The other side is a radio
  selector over saved addresses, or absent.
- **The reveal is a page load, not a JS event.** The shared-address control renders
  only inside the delivery form, and CHECKED there means the two addresses are the
  SAME; on every later pass core renders a link whose href navigates. There is no
  client-side toggle to bind to.
- **Core rebuilds the form on a country change.** Its country handler is delegated on
  the document body, POSTs the address-form action, replaces every address-form
  wrapper with the response, and restores previous values with an input-only,
  value-only loop. Values survive; attributes — including our autofill marker and the
  hidden organisation-number field — do not.

So rule 2's stop-test — "the secondary address is open and contains information in
any field at the time of selection" — **cannot be evaluated in the DOM at selection
time on PrestaShop, ever.** At the moment the buyer picks a company on the shipping
form, there are no invoice fields in the document. The test therefore moves off the
moment of selection and onto the moment the secondary form ARRIVES, on its own page
load.

---

## C1 — the comparison basis is the mirror's LAST-WRITTEN value

**Decided.** Not the primary address's live value.

Comparing against the primary is provably broken: the instant the primary changes,
the secondary cannot equal it any more, so every legitimate re-sync would read as a
buyer edit and syncing would stop forever after the first divergence. Only "does this
field still hold what the mirror last put here" makes "still matches ⇒ still synced"
true.

That is what `data-two-autofilled-value` already stores per field, so this is the
EXISTING marker mechanism, extended to every comparable field and made trim +
case-insensitive per R3. Three consequences, all of them behaviour rather than
corners:

- **A field the buyer typed BEFORE any mirror write** has no last-written value and
  is non-empty, so it mismatches and the address is pinned. Correct.
- **An EXISTING saved address opened for editing** is non-empty with no last-written
  value, so it is pinned and never synced over. **This is how the silent-overwrite
  defect earlier called "B2" is closed — by Doug's rule, with no separate
  new-versus-existing heuristic.** None was added, and none should be. Concretely: a
  text input gets NO server-rendered baseline. Accepting its rendered `value`
  attribute as "unanswered" is exactly what would sync straight over a saved billing
  address.
- **An empty field with no last-written value** matches "nothing written, nothing
  there", and is still synced.

The one field that cannot use emptiness as its unanswered test is the country select,
because core always renders a real country as selected — see
`serverRenderedSelectValue()`. Its unanswered value is therefore what the server
rendered, and that counts **only while nothing is on record as written there**: core
re-renders the form on every country change, so after the first change the
server-rendered country IS the buyer's own choice, and accepting it unconditionally
would make the country unpinnable.

**Residual, stated rather than hidden:** a buyer who reaches the invoice form, has it
mirrored, then navigates away WITHOUT saving and comes back finds an empty form with a
last-written value on record — which reads as a deliberate clear and pins the address.
Empty-after-a-write and never-filled-in-the-first-place are indistinguishable across a
page load, and Doug's rule resolves the ambiguity toward never overwriting. The cost is
one lost re-sync; the alternative default costs the buyer's own data.

---

## C2 — the pin is ADDRESS-WIDE, not per-field

**Decided.** R1 says any field pins it; R3 says the test is a content match. Together:
every comparable field of the secondary address is evaluated, and if even ONE
mismatches its last-written value, the whole secondary address is pinned and NO field
is synced.

Stated plainly, because it is the behaviour: **the mirror only ever writes into a
pristine secondary address, and once the buyer touches anything it stays frozen for the
rest of the cart unless the contents come back to matching.**

**Which fields.** Company, organisation number, country, street, postcode and city —
the fields the plugin can ATTRIBUTE, because it writes them and therefore has a
last-written value for them. The name fields and the phone are deliberately excluded:
the plugin never writes them, so every value in them is buyer-authored by definition,
and counting them would pin the secondary address the moment the buyer typed the name
they are obliged to type before they can save it at all — on the first render, before
any sync could ever have happened.

**Where it is evaluated.** In `mirrorConfirmedCompanyToInvoiceAddress()`, as the gate
in front of POPULATE only. The four-way operation split is kept, not collapsed: RE-MARK
runs first (it never writes a value), then the pin, then POPULATE, then COMPLETE and
RE-PUBLISH. The last two are rebuild REPAIR rather than sync.

**RE-PUBLISH is inert on a pinned address; COMPLETE deliberately is NOT.** Both are
gated on the mirror's own marked company name still being in the form, which makes them
inert whenever the COMPANY field is what raised the pin — but a pin raised by any other
field leaves that name intact, and the completion then still fires. That is the decided
behaviour, not a gap. COMPLETE is not syncing new data into the address: it repairs the
number half of a pair the plugin itself created, it writes only into an identification
field that is empty and carries no marker of any kind, and only while the name half is
still the mirror's own marked value. Gating it on the pin would reintroduce the defect
it exists to close — a mirrored company name on the order with no organisation number
beside it, on a form where core requires one — and it costs the buyer nothing, because
an empty unmarked field holds no answer of theirs.

---

## C3 — (a.3) is phrased by ROLE, never by primary/secondary position

**Decided.** Doug's parenthetical treats WooCommerce's billing address as the
"secondary", but the verified platform fact is that WooCommerce is **billing-FIRST**,
so billing there IS the primary. The general rule is therefore phrased as:

> Read the address playing the billing/invoice role. Where that address is not the one
> the platform has the buyer edit by default, it is synced from the default-edited one
> by the same content-match mechanism.

That is correct on both platforms. On PrestaShop the billing role is the invoice
address, which IS the secondary, matching Doug. On WooCommerce the sync clause simply
does not apply, because the billing address is the one the buyer edits first.

**OPEN QUESTION FOR DOUG (C3):** his (a.3) example describes WooCommerce's billing
address as the secondary one, which contradicts WooCommerce being billing-first. The
rule above is written so that it is right either way and does not depend on resolving
the contradiction — but if his mental model of WooCommerce is billing-second, then the
WooCommerce port's primary/secondary mapping needs his correction before it is written,
because the sync direction inverts. Not resolved here, and deliberately not "corrected"
in his wording.

---

## Where the last-written values live

The pin is evaluated when the invoice form APPEARS — a page load. At that moment every
marker the previous page wrote has gone with the nodes that carried it, and page memory
is empty. So the last-written values MUST survive page loads.

**A SEPARATE cart-scoped record, following the discipline of the company record**:
`Twopayment::MIRROR_WRITE_SESSION_KEYS` and `MIRROR_WRITE_SESSION_CART_KEY`, with
`storeTwoCartScopedMirrorWrites()` / `readTwoCartScopedMirrorWrites()` /
`clearTwoCartScopedMirrorWrites()`, `getTwoCurrentCartId()` for the stamp, and an
absent-or-mismatched stamp treated as absent. Published to the browser as
`mirror_writes` alongside `confirmed_company`, and reported back by the browser through
a `saveMirrorWrites` action on the module's front controller, token- and POST-guarded
like every other action there.

**NOT folded into the company record**, and that is load-bearing rather than tidy. The
company record is deliberately destructible: its country guards clear it outright, and
the address-save hook drops half of it. This record has to outlive all of that — a
buyer who typed their own street into their billing address still owns that street after
changing company. Folding them together would mean either a clear site exempting one of
its own keys, which contradicts the contract that a clear cannot miss a field, or this
record dying on a path that has nothing to do with it. `MirrorWriteRecordSpec` pins both
directions and the one event that does invalidate both (a cart-id change).

**Failure mode of the report is the safe one.** It is fire-and-forget, like the
company clear beside it. A request that never arrives leaves the next render seeing
non-empty fields with nothing on record as having written them, which reads as
buyer-authored and PINS the address. A lost report costs one missed re-sync; the
opposite default would cost the buyer's own data. So there is no server-side backstop
and none is owed — unlike the company-clear path, where a dropped request would yield a
wrong order rather than a conservative one.

**The country is stored as an ISO code**, never as a country id or an option label: the
id is shop-local and the label is locale-dependent, so either would make the record
unreadable on another shop or in another language. The browser resolves the live select
to an ISO before comparing, through `countryIsoForOptionValue()` — the inverse of
`countryOptionValueForIso()`, with the same three strategies in the same order.

---

## Rule 3 — already correct, and now pinned by a spec

**Country: no resolver work is owed.** `getCheckoutBillingCountryIso()` derives
`window.twopayment.billing_country` from the cart's `id_address_invoice`, and core sets
`id_address_invoice` to the delivery address whenever the buyer states the addresses are
the same (in the address-save branch and again in the address-confirmation branch of the
addresses step). So the published billing country is the invoice ROLE's country on both
paths.

**(a.3) — the payment tile — verified, and needs no new resolver.**
`TwoSoleTrader.billingCountry()` reads the address form's country select when there is
one and falls back to `config.billingCountry`, i.e. that same published value. On the
payment step PrestaShop renders no address form and therefore no select at all, so the
fallback is what the tile actually uses — which is the billing/invoice address,
explicitly, exactly as R2 (a.3) requires. Pinned by a spec; no resolver added.

**(a.3) — the company half is role-keyed too, by the same key.**
`getTwoBrowserCompanySelection()` withholds a record whose country disagrees with
`getCheckoutBillingCountryIso()`, and withholds one whose captured address id disagrees
with the cart's `id_address_invoice`. The tile's own "did the buyer pick a company"
check, `TwoCheckoutManager.isCompanyDataMissing()`, reads the hidden organisation-number
field and the confirmed selection, which on the payment step is exactly that published,
role-validated record.

One consequence the rework depends on: once the buyer saves a distinct invoice address,
the captured-address guard **withholds** a company captured on the shipping address, and
the tile then depends on the company/organisation-number pair actually being ON the
invoice address row. That is why the mirror carries the organisation number and not just
the name, and why the pairing machinery below is mandatory rather than tidy.

**(a.1) and (a.2) — the local read is CORRECT and stays.** The search mounted in the
delivery address reads that address's own live country; the search mounted in the
invoice address does the same. That is already how it works and it was left alone.

**`resolveSoleTraderCountryIso()` stays exactly as #12 shipped it** — posted country
first, the cart's delivery address as the only last resort, the committed invoice
address consulted at no tier. The earlier note in this document argued for restoring an
invoice tier; Doug ruled against it, and the argument is withdrawn rather than left
standing.

**Its scope was always case (a) only, verified by finding every caller.** There is
exactly ONE: `ajaxProcessSoleTraderTokens()`. What that country decides is the
availability gate (`TwoSoleTrader::isAvailable()`), whether the tokens are minted at
all, and the value echoed back for the JS to save the enrolled company against. It
reaches no order data: the order payload and the order-intent handler resolve country
from the address they are handed, never from this method. **Order-intent / order-data
country resolution is out of scope for this work and was not touched.**

---

## Rule 4 — company enforced only on the billing role

**One resolver, three evaluation points.** The role notion is "does this address play
the billing role", and it is asked of three different things:

- **the visible form** — it plays the billing role when it is the invoice form, or when
  it is the delivery form and the buyer's current selection indicates the two addresses
  are the same. Built from the two helpers the branch already has:
  `visibleAddressFormType()` (read from the hidden field core's form emits carrying
  exactly that word) and `buyerStatesInvoiceAddressDiffers()`. `secondaryAddressFormRoot()`
  is the composition of those two plus the fail-closed scope guard.
- **the submitted form** — the same predicate over the request params: the address save
  is for the invoice side, or it is for the delivery side and carries the shared-address
  control indicating the addresses are the same.
- **the committed cart** — `id_address_invoice`, which is what rule 3 already reads.

That is ONE notion evaluated at three moments, not three notions. Rules 2, 3 and 4 all
consume it, and nothing may add a second.

**Where company is enforced today, checked:**

| site | fires regardless of address? | verdict |
|---|---|---|
| `override/classes/form/CustomerAddressFormatter::getFormat()` | it does not enforce company at all — it moves the country field, adds the search placeholder, and forces `phone` required | **company is required NOWHERE today, on either address.** So "optional on shipping" already holds. If Doug wants real enforcement on the billing side, this file is the precedent — but note it is handed no address ROLE, so it would have to derive one from the request params (the submitted-form evaluation point above), and it also runs on my-account address forms where there is no role at all |
| `TwoCheckoutManager.isCompanyDataMissing()` | role-agnostic by construction — it reads the hidden organisation-number field and the confirmed selection, on the payment step, where no address form exists | **no change owed.** Its inputs are already the role-validated published record |
| `controllers/front/orderintent.php` — the `no_company` / `incomplete_company` gate | gates on the resolved organisation NUMBER, resolved through the invoice-preferring chain | **already role-keyed.** The delivery-address last resort is only reached on a cart with no invoice address at all |
| `twopayment.php` — `getTwoValidatedSessionCompanyData()`, `getCompanyDataWithFallbacks()` | take the address they are given; the order-payload path gives them the invoice address | already role-keyed by their callers |

So rule 4 is **mostly satisfied by absence**, and the deliverable is: keep it that way
deliberately, and if a hard requirement is added, add it at the formatter keyed on the
submitted-form role — never unconditionally, or the shipping address starts demanding a
company the buyer has no reason to give.

---

## Rule 1 — editability needs NO code, confirmed

**Nothing in PR #157 ever made the secondary address read-only.** The mirror only
declines to overwrite, and the pin only makes it decline more often. The company field's
`readonly` in search mode is the search affordance, applies identically to both
addresses, and the search control mounts on whichever form is visible — so the buyer can
already search or type a company on the secondary address. **No editability feature was
invented and none is owed.** Doug's correction was phrased as though read-only controls
existed; they do not.

---

## The judgment calls, as ruled

The earlier version of this note listed seven judgment calls. Their disposition:

1. **Buyer types something, deletes it back to empty, then the primary changes.**
   Answered by C1: an empty field WITH a last-written value mismatches, so the address
   stays pinned. Not a separate rule — it falls out of the content match.
2. **Buyer edits the secondary after the mirror wrote it (the NI company with an RoI
   branch), then changes the primary.** Answered by C1: their edit no longer matches
   what the mirror wrote, so the address is pinned and their correction survives. The
   mirror's own write is NOT what pins it; the buyer's edit on top of it is.
3. **Does "any field" include street, postcode and city?** Answered by R1: yes. The cost
   Doug was asked to price — a buyer who fills only the street blocks company syncing for
   the rest of the cart, in a field the mirror never writes itself — is accepted.
4. **"Addresses differ" re-opened after a collapse.** Stale data is overwritten, never
   cleared: clearing means deleting buyer data from an address row on a navigation, which
   is a far worse failure than a stale street the buyer can see and correct. Where the
   mirror has nothing to write, it is a no-op and "syncing is restored" visibly restores
   nothing. Unchanged from the earlier recommendation, and now unconditional because there
   is no flag to resume — the content match is the whole state.
5. **The buyer picks a SAVED address for the invoice role.** There are no fields to sync
   into; the invoice side is radio buttons. No write, and nothing to pin — the next render
   of an editable form judges that address's own contents, which for a saved address are
   non-empty and unattributable, so it is pinned by C1 without a special case.
6. **`resolveSoleTraderCountryIso()`'s last resort.** Ruled by R2: unchanged. See rule 3
   above.
7. **Is there a general "the addresses are the same" control on later passes?** Moot. R3
   removes the need for one: there is no flag to resume, so there is no resume trigger to
   find. The routes back that core does offer (edit the delivery address and state "same",
   cancel out of the invoice form, select the delivery address in the invoice selector) all
   end in a page load whose form contents the pin judges afresh.

---

## What of PR #157 survives, what changed

**Survives unchanged** — all on `TwoCompanySearch` unless noted:

- `buyerStatesInvoiceAddressDiffers()` — still the gate, still polarity-neutral, still
  named for what the buyer states.
- `visibleAddressFormType()` — the other input to the role predicate.
- `visibleAddressFormRoot()` and `ADDRESS_BLOCK_SELECTOR` — the write scope, and the
  fail-closed guard that rejects a candidate containing another address block.
- `serverRenderedSelectValue()`, `mirrorCountryIntoForm()`, `countryOptionValueForIso()`,
  `writeMirroredValue()` — the per-field write layer.
- `reapplyMirrorMarkers()`, `completeMirroredOrganizationNumber()`,
  `republishMirroredSelection()` — rebuild-repair machinery, independent of the sync
  rule. All three exist because core's rebuild strips attributes and destroys the hidden
  organisation-number field, and that is still true.
- `confirmedCompanyForMirror()` and the injected getter, plus
  `TwoCheckoutManager.seedConfirmedCompanySelectionFromServer()` and the module's
  read-only `getTwoBrowserCompanySelection()` publish. The mirror still has to cross a
  navigation.

**Changed:**

- **The write-once gate is now inner.** `populateInvoiceAddressFromConfirmedCompany()`'s
  once-per-company rule is no longer the outer decision: the pin is. The once-per-page
  key stays keyed on the organisation number, which is correct now that a primary COUNTRY
  change re-syncs through the pin rather than through that key.
- **`mirrorTargetIsWritable()` compares normalised** (trimmed, case-folded) per R3, and
  takes a LIST of accepted values rather than one. The list comes from
  `mirrorWriteAcceptedValues()`, which applies the same rule the pin does — the recorded
  last-written value, and only when there is none, the field's unanswered value. One
  helper, so the pin and the write layer cannot drift.
- **`autoFillAddress()` takes an optional root** and returns what it now owns. Street,
  postcode and city were the only writes in the class still made by a document-wide
  selector, so a value in one of them could not be attributed to a BLOCK at all — and R1
  makes attributing them mandatory. The document-wide branch is unchanged and is what
  runs on the shipping pass, on the payment tile and on any page with no address form
  at all. It does NOT run when the invoice form is on screen and the scope resolution
  fails closed: there is no block to attribute the writes to, and writing document-wide
  there would land in the very markup the scope guard refused to scope to. That fill is
  skipped instead — no scope means no write, the same answer the mirror gives.
- **`mirrorMemory()`'s role** stays page-lifetime and stays on `TwoCheckoutManager`, but
  it is no longer the record of "may I write" — only of "what did I write on this page",
  for the re-mark and completion operations.

**Added:** the cart-scoped mirror-write record and its ajax action (above);
`secondaryAddressFormRoot()`; `MIRRORED_ADDRESS_FIELDS`;
`persistedMirrorWrites()`; `normalizeMirroredValue()`; `countryIsoForOptionValue()`;
`mirroredAddressFieldStates()`; `mirroredFieldStillHoldsWhatWeWrote()`;
`mirrorWriteAcceptedValues()`; `secondaryAddressIsPinned()`; `recordMirrorWrites()`.

**Deleted:** nothing. In particular no suppression FLAG was built — the earlier design's
persisted boolean, its browser-side authorship listener, its address-save backstop and
its resume detection are all superseded by R3's content match, which needs none of them.

---

## Interaction with B1's pairing fix — mandatory, not optional

Every mirrored write of a company name must go through the same `organizationField` +
`data-two-company-name` pairing machinery a manually-typed primary selection uses, via
`markOrganizationFieldSelected()`. **The tag is mandatory.** An untagged non-empty
`companyid` is read by `clearStaleOrganizationSelection()` as company-set / number-set /
tag-absent — the signature of a buyer having edited past a stale selection — and it wipes
the pairing. So an untagged mirrored write does not merely go unnoticed; it is actively
destroyed by the plugin's own guard on the buyer's next keystroke in the company field.

This is why `republishMirroredSelection()` exists as a separate operation and must not be
folded into the populate: the hidden field lives inside the address-form wrapper core
replaces, its input-only restore loop cannot put back a field the new render does not
emit, and the mirror's own country write is what triggers that rebuild. Under the rework
this is MORE load-bearing, not less, because sync can now fire repeatedly across a cart
rather than once.

---

## Tests

**JS** — `tests/js/company-search-secondary-address-pin.test.js`, one group per ruling:
the pristine baseline first so nothing else can pass by never syncing; R1's any-field
cases including the street, the postcode, the city and the identification number; C2's
address-wide case, where every other field matches perfectly and one does not; C1's
comparison basis, driven with a primary whose live value is neither the recorded value
nor the field's; R3's trim and case-fold; the existing-saved-address case that closes B2;
and the persistence pair — the same DOM twice, differing only in whether the record
reached the page.

`tests/js/company-search-invoice-mirror.test.js` and
`tests/js/checkout-manager-confirmed-company-seed.test.js` are unchanged and still pass:
their per-field refusals are now attributable to the pin as well as to a marker
mismatch, and both remain true.

`tests/js/ps-harness.js` gained `address1` / `postcode` / `city` options on
`buildAddressesStep()`, emitted as real `value` ATTRIBUTES — which is what the server
does when the buyer is editing an address that already exists. Without them the harness
could not express the case C1 resolves at all.

**PHP** — `tests/MirrorWriteRecordSpec.php`: the record's cart scoping, its partial and
null write semantics, the ajax action driven through the controller's own action switch
with its token and POST guards, and above all the two-record separation in both
directions plus the cart-id change that invalidates both.

**Cannot be tested offline; needs a live shop:**

- Core's actual rebuild round trip on a country change — the Jest harness simulates the
  replace-and-restore, and the thing that keeps biting is a discrepancy between the
  simulation and what core really emits.
- The identification field appearing and disappearing with the country, which depends on
  real address-format data. Only ES and MX carry the identification-number flag on stock
  data, and the stock address formats mention it nowhere, so the field is appended
  dynamically and is present exactly when required.
- The navigation sequence itself: the differ-link GET, the delivery save carrying the
  shared-address control, the invoice-form render, and what core's session actually does
  to `id_address_invoice` at each step.
- The `saveMirrorWrites` round trip against a real cookie and a real cart.
- The end-to-end rule 3 assertion: place an order with a company on the secondary
  address and confirm the tile and the payload both carried the secondary's company and
  country.

---

## Still open

- **C3's WooCommerce contradiction** (above) — needs Doug, before the WooCommerce port.
- **Rule 4's hard enforcement**, if he wants any. Nothing enforces company on either
  address today; the note above says where it would go and why it must be role-keyed.
- **#8** remains DEFERRED. No `TwoCountry.js` extraction was made and the duplicated
  country-name maps are untouched.
- **The ~6-way selection-state duplication** is recorded as a follow-up. Not refactored
  here.

---

## Cross-platform portability

Language convention, and it is a code requirement here rather than a reporting one:
**"when the buyer states" / "the buyer's current selection indicates", never "ticked" or
"unticked", "checked" or "unchecked"** — in method names, comments, test names and docs.

The reason is concrete. PrestaShop's shared-address control means **checked = the two
addresses are the SAME**, so the plugin's question is its negation; it renders on the
first pass only; and WooCommerce's equivalent control has the **opposite polarity**. An
engineer porting this and reading "checked" wires it up backwards. The note lives on
`buyerStatesInvoiceAddressDiffers()`, which is the signal helper every consumer goes
through.

- **WooCommerce** is billing-FIRST. The primary/secondary labels invert, so a port that
  hardcodes "invoice is the secondary" is wrong there while looking right here. Only the
  billing-ROLE predicate ports; the primary/secondary naming does not. Its control also
  has the opposite polarity, and both address panels can be open together — which means
  rule 2's literal DOM test IS evaluable there, and the persisted last-written record is
  a PrestaShop-specific necessity rather than the shared design. See the C3 open question.
- **Magento / Hyvä** are shipping-first like PrestaShop, so the role mapping ports
  directly. But their checkout is a single page with reactive components, so the reveal is
  an event rather than a navigation, and the last-written values can be component state
  where here they must be server-persisted.

Write the content match and the role predicate as the portable core, and keep the
persistence and the reveal detection explicitly platform-local.
