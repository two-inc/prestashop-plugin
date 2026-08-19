# Sole-trader / company-search: porting guide (PrestaShop → Magento / WooCommerce)

Source of truth: `two-inc/prestashop-plugin` `staging`, PRs #153–#168 (TWO-40). This
guide is the distilled design + gotcha list for reimplementing the same behavior on
another platform. Read it before writing code — most of the entries exist because a
naive first attempt on PrestaShop got it wrong and had to be corrected, often more
than once, sometimes reversing an earlier decision entirely.

## 0. The mode chips are DOM children of the search dropdown, not a separate widget

**This was missing from earlier drafts of this guide, and its absence caused the same
wrong port to happen twice independently** (once in an initial WooCommerce audit that
called existing tile-only placement "already correct," once in an unrelated from-scratch
reimplementation that reasoned "the guide specifies how each placement must read, not
that a placement must be added" and left the chips as a separate persistent tile
element). Both were wrong for the same reason: the guide talked about *placement*
(§1 below) without ever stating the one fact that actually constrains chip markup.

Confirmed directly from PrestaShop's live DOM (the staging dev shop, GB
address step, real click-and-type interaction — not forced attribute manipulation,
not inferred from source). This section was itself wrong on two details in an earlier
draft and has now been corrected against real evidence a third time; treat every
claim below as DOM-verified, cited by the actual structure captured live:

- **Real nesting** — `div.two-company-dropdown` has exactly three direct children:
  `.two-company-dropdown__search` (query input + spinner), `.two-company-dropdown__results`
  (the autocomplete `<ul>`), and `.two-company-mode-chips` (the three
  `.two-company-mode-chip` buttons). The chips are NOT direct children/siblings of the
  search input — there is one intermediate wrapper (`.two-company-mode-chips`) between
  the dropdown panel and the individual chip buttons. Two levels: chip →
  `.two-company-mode-chips` → `.two-company-dropdown`.
- **Visibility is purely inherited, confirmed with zero independent chip state.** Every
  chip's `style` attribute is present but empty (`element.style.display === ''` in
  every observed state); there is exactly one visibility switch in the whole
  structure — the `hidden` attribute on `.two-company-dropdown` itself. Open it, all
  three chips become visible; close it, all three become invisible; nothing about an
  individual chip's presence is ever toggled independently. (Which chip is
  *selected* — `.two-company-mode-chip--selected` — is a separate, purely cosmetic
  class change, not a visibility mechanism.)
- Consequence for porting: **do not build the chips as an always-visible element
  fixed in the payment tile or address form.** Whatever placement §1 resolves to
  (a.1/a.2/a.3), the chips render *inside that placement's own search-dropdown panel*,
  as children of one intermediate wrapper, appear only while it's open, and disappear
  when it closes — exactly like the search results they sit next to. A platform whose
  company-search control has no dropdown panel concept at all needs one added; that is
  in scope for this port, not an acceptable reduction of it.
- The old "my company is not on the list" link-style fallback is NOT additional UI
  alongside the chips — it no longer exists as separate copy on PrestaShop (confirmed:
  zero occurrences of "not on the list" or "my company" anywhere in the page, in any
  state). It has been fully absorbed into the third chip, class
  `two-company-not-listed`, visible label "Enter manually." A port that keeps its own
  platform's old fallback link *and* adds the chips as a second, separate mechanism
  has not implemented this design — it has implemented two overlapping ones.
- **Chip selection must survive a reopen — was a live PrestaShop bug, now FIXED
  (`1c1b3d7`), so port the fixed behaviour, not the old one.** Reopening the panel
  used to reset `--selected` to "Registered Company" every time, whatever was last
  chosen. The root cause is worth knowing because the wrong fix is tempting: the
  reopen handler reset the mode unconditionally, reasoning that cancelling an
  in-flight enrolment on reopen had already reversed the only other way the mode
  could be `sole_trader`. That is true of an in-flight ENROLMENT and false of an
  already-ADOPTED identity — cancelling a signup in progress does not un-adopt a
  completed one. Derive the reopened selection from whether an identity is adopted
  (PrestaShop reads the presence of the "select a different sole trader" element as
  the single source of truth, rather than a second flag that can drift from it), not
  from a mode variable the reopen path is free to clobber. Full rules in §11.
- **Chip labels are sentence case** — "Registered company", "Sole trader", "Enter
  manually" (`1c1b3d7` aligned PrestaShop onto WooCommerce's existing wording). Not
  Title Case, on any platform.
- **The "select a different sole trader" element is OUTSIDE the dropdown, not inside
  it — this corrects an earlier wrong edit to this guide.** It is a `<button>`
  (`.two-company-select-different-sole-trader`), appended as the LAST child of the
  outer field wrapper (`.two-company-field-wrap`), landing as a following SIBLING of
  `.two-company-dropdown` — never a descendant of the dropdown panel or of
  `.two-company-mode-chips`. It renders only after a sole-trader identity has been
  adopted, in normal block flow below the company field, deliberately never
  overlapping the (possibly-open) dropdown or the org-number hint that shares the
  same wrapper. There is NO equivalent element for an adopted *registered* company at
  all — re-searching a registered company is done by clicking the (readonly) company
  input itself, which reopens the dropdown; only the sole-trader case gets an
  explicit standalone "pick a different one" affordance.

## 1. Two independent "which country/company" questions

Never conflate these two. They were originally 3-way (then 4-way) mirrored across
different resolvers on PrestaShop and that duplication was the root cause of several
bugs — resolve country/company ONE way, reuse it everywhere.

- **(a) Chip visibility + workflow input** — what drives whether the sole-trader
  chip renders, and what country/company feeds the enrollment/signup/token-mint
  calls.
- **(b) Order intent / order data** — whatever actually gets submitted with the
  order. Out of scope for this porting pass; whatever already resolves it keeps
  doing so unchanged. The only place (a) and (b) must agree: the org-number/company
  name persisted for admin/webhook-time use (§6) must come from (b)'s resolution.

Case (a) splits into three placement scenarios — phrase these by ROLE
(billing/invoice vs shipping/delivery), never by "primary/secondary": PrestaShop,
Magento/Luma, and Hyvä default shipping-first (shipping is the always-shown
"primary" form); **WooCommerce defaults billing-first** — a real inversion, not a
naming quirk. A naive port of PrestaShop's shipping-first logic reads/writes the
wrong block on WooCommerce every time.

1. **(a.1) delivery/shipping address form.** Chip visibility + workflow
   country/company read LIVE from that same form's own current fields — never a
   committed/saved value, never a different address.
2. **(a.2) invoice/billing address form.** Same rule, same live-local read.
3. **(a.3) payment tile** (no address fields of its own). Read explicitly from
   whichever address plays the billing/invoice ROLE — never "whichever address is
   primary" as a shortcut. If billing/invoice is the platform's non-default
   ("secondary") form, that value comes from the sync mechanism (§2), not an
   independent read.

**Security note, already investigated and disproven on PrestaShop, don't re-litigate
it per-platform without cause:** the token-mint call takes no country parameter at
all — country only feeds an availability-gate lookup, which has no security
consequence either way (a client can already query availability for any country
directly). Preferring the live in-page value over a committed one is a pure
correctness win, not a security tradeoff.

## 2. Two-address model: editable + synced, never locked

**Rejected design, don't reintroduce it:** making the non-default ("secondary")
address's company/country read-only, mirroring the default one. Real business case
that killed it: a company based in NI with an RoI branch — same legal entity, no
separate registration, but a genuinely different valid local country/company
pairing on the second address.

**Current rules:**

1. The non-default address stays fully editable, always.
2. Default behavior: changing country/company on the default address propagates to
   the non-default one.
3. **Sync-stop is a pure content-match check, not a discrete flag.** Before writing
   a mirrored value into any field, compare (trimmed, case-insensitive) its current
   content against what the mirror would write. Still matches → still "synced",
   overwrite freely. Even ONE field not matching → the **whole address** (not just
   that field) is buyer-edited and pinned. This whole-address granularity was an
   explicit ruling — don't scope the pin per-field.
4. **No dedicated "resume sync" control.** The match check in (3) handles resumption
   automatically — collapsing back to one address and reopening naturally re-matches
   the mirror's expected state. An earlier, more complex "explicit flag tied to UI
   events" design was deliberately dropped in favor of this.
5. **Track every field the mirror can write**, for the pin-check, not just
   country/company: also address line 2 and the state/region field. A first attempt
   left these out reasoning they were "safe" — wrong; a buyer typing into address2 is
   exactly as strong a signal of independent editing as typing into city.
6. **Field-routing when writing an address from an external payload** (autofill,
   registered-company search — same rule for both, don't special-case sole trader
   here):
   - `building`/`apartment` present → line 1; `street` → line 2.
   - `building`/`apartment` absent → `street` → line 1, line 2 untouched.
   - **No dedup check** between the two even if textually identical — some real
     addresses legitimately have matching first/second lines.
   - `region`: write to a state/county field if the address format has one
     (best-effort text→id match, inherently lossy but attempted); otherwise append
     to `city` with a comma (`"Ashford, Kent"`).
7. **Company/org-number requirement scope:** required ONLY on whichever address
   plays the billing/invoice ROLE (§1's a.3 resolution) — never on a shipping-only
   address. Reuse the same role-resolution logic; don't build a second one.

## 3. `TWO:`-prefixed identifiers: exactly one special case, and it's cosmetic

**Rejected design:** treating internal (`TWO:`-prefixed) identifiers — sole trader
OR registered-company (confirmed: also issued to some US companies with no separate
public registration, not sole-trader-exclusive) — as special anywhere in
storage/pairing/validation/routing. This caused two real bugs: a mismatched
name/number pair surviving a validation refusal, and a "name must always travel with
its number" invariant broken by design (could hard-block a buyer whose country
requires an identification field).

**Corrected rule:** a `TWO:` value goes through the exact same single write/pairing/
mirror/validation/submission path as any other org number — no branch anywhere.
**The only exception is display**: hide it wherever the org-number field is shown to
the buyer, on the containing form-group (`display:none`), not just the input — hiding
only the input orphans its label.

**The one real, unavoidable platform wrinkle:** if the target platform has a native
"identification number"-style field with its own format validation (PrestaShop's
Spain-format `dni`: rejects a colon, 16-char cap), a `TWO:` value can fail to save
there outright. **Fix by never routing to that one field, not by stripping the
prefix** — the prefix is meaningful to the API and must never be stripped, anywhere,
for any reason. Check the target platform for an equivalent trap before assuming it's
safe (WooCommerce/Magento's own persistence fields had no such trap when checked —
that's not proof the next platform won't).

## 4. Cross-platform persistence check (why this matters)

WooCommerce and Magento already persist org number + company name durably,
independent of session/cookie state: WooCommerce via order meta (`company_id`,
unprefixed key, `TWO:` values stored raw) + user meta; Magento via a genuine EAV
attribute on the customer-address entity (varchar(255), no validation) + the order
payment's `additional_information` JSON blob. Neither platform's admin order screen
actually surfaces it, though (matches the corrected PrestaShop state).

**PrestaShop's own equivalent, shipped this session (§6):** two new columns on the
module's own order-keyed table, following the exact precedent already in that table
for a different "checkout-time value needed later at admin/webhook time with no
session available" problem (`two_day_on_invoice`/`$storedTerm` in
`getTwoUpdateOrderData()`). If the target platform already has durable order-scoped
storage (it does, per above) — reuse it, don't build a parallel mechanism.

## 5. Write-back state machine — the recurring root cause

This was the single most repeated bug source this session. Any platform's sole-trader
implementation needs the equivalent of:

- **A pairing tag**, recording which org-number selection a given company-name value
  was chosen under. On the next input/change to the company field, if the tag is
  absent or doesn't match, wipe the org number and any dependent state — this stops a
  stale org number silently surviving a retype.
- **A single write-path helper** that sets the company-id/org-number field AND its
  pairing tag atomically, in one call. Any code that sets the org-number field
  without going through this helper gets silently wiped by the guard above on the
  very next interaction. This bit multiple PRs this session (write-backs, mirror
  writes, sole-trader adoption) before the pattern was fixed into one shared helper.
- **A provenance marker**, separate from the pairing tag, recording that a given
  field's current value was written by the plugin (vs typed by the buyer) — needed
  by the pin/mirror logic in §2 to distinguish "still what we wrote" from "buyer
  edited this."
- **The write-back function must NOT be gated on the same "is address-lookup enabled"
  switch that gates an ordinary company-search selection's address write.** On
  PrestaShop, `adoptSoleTraderBuyer()` initially routed through the same
  `autoFillAddress()`/`writeOrganizationToAddressIdentifiers()`/`autoFillRegion()`
  methods an ordinary search-result selection uses — all three early-return when that
  switch is off (which it legitimately is whenever company-search isn't mounted in
  the address area). Sole-trader signup completion must write regardless of where
  company search is mounted; add an explicit bypass parameter rather than trying to
  make the switch context-aware.
- **A real browser paints on a delay; a synchronous DOM-class assertion in a test
  harness does not.** A "chip shows as selected" fix that sets a CSS class and closes
  a dropdown in the same synchronous tick can pass its own unit test (no render step
  in a virtual DOM) while never actually painting a visible frame in a real browser
  before the dropdown hides. If a visual-state fix's own test passes on the first
  try, don't trust it alone — verify with a real browser and watch the timing, not
  just the end DOM state.
- **Order-intent / any other side-effect call triggered by the write-back must not
  fire before the buyer reaches the step that's supposed to trigger it.** Check
  whatever gates the platform's ordinary intent-creation flow (usually "buyer reaches
  and selects a payment method") and make sure the sole-trader path is gated by the
  exact same condition — don't let a signup-completion callback call intent-creation
  directly.

## 6. Order-scoped persistence pattern (Option A + C from this session)

If the target platform's native org-number-carrying field has the validation trap in
§3: skip writing to that field for `TWO:` values (Option A) AND persist org number +
company name on the platform's own order-scoped storage (Option C — reuse an
existing durable per-order store, per §4; on Magento/WooCommerce this likely means no
new storage at all, just make sure the sole-trader write-back path populates the
SAME field the ordinary flow already uses for this, and audit whether any admin/
webhook-time PUT-style code path can send an empty value where a resolved one should
be — this exact bug existed on PrestaShop's admin-edit and tracking-number-update
paths before Option C).

The value persisted must be **the resolved billing/invoice address's org number
specifically** — the same value driving order-intent/order-data (§1's out-of-scope
case (b)) — not whichever address the sole-trader UI happened to run in.

## 7. UX additions, implement alongside the core flow, not as an afterthought

- **In-flight spinner:** while the sole-trader autofill/token-fetch/signup-check
  round trip is running, keep the company-search control OPEN (don't close it) and
  show a spinner inside the query/search input field itself — not a separate overlay.
  Wire it to the real async duration (resolve on an actual "flight settled" event
  fired from every terminal branch of the async call graph — success, failure,
  retry-exhausted, abandoned), never a fixed timeout. Adversarial review on this
  exact feature found real races on every iteration (stuck-forever spinners on two
  different abandon/retry paths, a missing re-entrancy guard causing double signup
  popups, a guard released too early) — budget for multiple review rounds, don't
  expect to get this right in one pass.
- **"Select a different sole trader" link** — placement per §0's corrected finding,
  behaviour once adopted per §11 (it is one of exactly two entry points into the same
  relaunch call, not the only one). Concretely:
  this is a standalone button appended as the LAST child of the company field's
  outer wrapper, landing as a SIBLING AFTER the dropdown panel — not inside it, not
  inside the chip group. Only renders once a sole-trader identity has been adopted;
  there is deliberately no equivalent for an adopted registered company (that case
  reuses clicking the field itself to reopen search). Clicking it must skip the
  normal cookie/silent-autofill pre-check and launch the popup directly with an extra
  flag appended (PrestaShop used `autoselect=false`, currently unread by the backend —
  just wire the parameter through unconditionally, no client-side branching on its
  value). The single popup/link covers BOTH "pick a different existing registration"
  and "register a new one" — that choice happens inside the third-party popup's own
  UI once it's open; the plugin doesn't need to distinguish the two cases itself.
- **Popup window size:** `700×805` (width×height) — not narrower; a too-narrow popup
  clips the hosted signup flow's own layout.
- **Popup mechanism stays `window.open()`, not an iframe.** The signup/OTP flow
  depends on a third party that only works in a real popup window — don't attempt an
  iframe-in-overlay rewrite even though it would sidestep popup-blocker risk; that
  tradeoff was explicitly evaluated and rejected. Keep the `window.open()` call
  synchronous with the click event that triggers it — an async-delayed
  `window.open()` risks being blocked by the browser regardless of platform.
- **Branding on the popup URL:** DO NOT build brand-overlay support pre-emptively —
  but leave a comment at the popup-launch call site (wherever the final URL is
  assembled) noting the real, confirmed precedent: Magento/WooCommerce resolve brand
  via a **per-brand hostname template** (`checkout_url_template`) — a distinct
  hostname per brand, so the host itself conveys the brand in production, rather than
  the base host. There's also a `?brand=<tag>&brandVersion=
  <ver>` query-string fallback, but it's explicitly documented as dev-loop-only, for
  when multiple brands temporarily share one non-prod domain — don't treat it as the
  primary mechanism to copy.

## 8. Email/identity trust levels — don't reuse a passive check on an authenticated path

A buyer-identity lookup that's correct for a PASSIVE, pre-auth context (matching the
current checkout form's email against a cookie/session, no proof of identity yet) is
WRONG if reused unchanged on a POST-AUTHENTICATION callback (e.g. the popup's OTP
step just succeeded — the server has already told the browser who the buyer is,
possibly under a different email than the checkout form's). Reusing the passive
check on the authenticated path causes a real, confirmed bug: a legitimate buyer
completes OTP successfully, then the same stale email-match heuristic silently
disagrees with the server and reopens the same popup, forever. Fix pattern: thread an
explicit "this identity is already authenticated" flag through to the buyer-lookup
function and have it skip the match check on that path — the email the buyer
authenticated with should drive lookup, full stop, with NO requirement to match
whatever's sitting in the order's contact-email field.

## 9. Local-dev tooling — this session added it to PrestaShop, may need building on WC

Independent dev-mode env-var overrides for all three service hosts a plugin talks
to — checkout/merchant API, merchant portal, and the hosted checkout-page app
(sole-trader signup + company search) — each falling back independently to its
platform-appropriate default, and ALL gated so a production instance can never
accidentally honor one even if the env var somehow leaks into its process
environment. Magento already has this (`TWO_API_BASE_URL`/`TWO_CHECKOUT_BASE_URL`,
gated on developer mode, documented in its README) — mirror that pattern rather than
inventing a new one. WooCommerce has NOT been audited for this yet as of this
session — check before assuming it needs building from scratch.

For a remote/non-local shop needing to reach a developer's laptop-hosted
checkout-page specifically (not the API/portal, which the shop's own server-side
process can usually reach via a normal Docker network alias): the browser is what
opens the popup, so the override host has to be one the BROWSER can resolve — an FRP
reverse tunnel exposing the local checkout-page dev server works and is what this
session used; don't assume `localhost`/`host.docker.internal` alone is sufficient
once the shop itself is remote (e.g. GKE-hosted), since neither resolves anything
useful to a browser pointed at a remote domain.

## 10. Process notes, not code, but save real time

- **Never trust a fix's own test as proof it worked live**, especially for anything
  visual/timing-sensitive. This session shipped a "fixed" chip-selection PR whose own
  regression test passed while the fix did nothing in a real browser (§5's paint-
  timing entry) — caught only by live re-testing after merge.
- **A round of adversarial review whose only findings are artifacts of the PREVIOUS
  round's own fix is oscillating, not clean** — don't merge on it, run another round
  against the latest fix specifically.
- **Live-verifying against a cached/CDN'd asset needs a cache-busted URL** — a bare,
  unversioned URL request can return a stale cached response even when the real
  deployed asset is already correct, producing a false "not deployed yet" read.
- Every "obvious" root cause on this feature turned out to need at least one
  correction once tested against real production-shaped data (a real buyer's
  existing address, a real merchant's registry answer, a real popped-open browser
  window) — budget live-verification time into every port, not just the initial
  unit-test pass.

## 11. Adoption is a MODE, not a populated field

All three rules below are Doug's, from live testing on 2026-08-19. They are one
design, not three fixes: **once a sole trader is adopted, that is the state of the
control**, and every surface has to agree with it. Rules 2 and 3 are implemented on
both platforms (PrestaShop `1c1b3d7`, WooCommerce `38bc49a` + `48edd08`); rule 1 is
implemented on WooCommerce and is a **known open gap on PrestaShop** — see its own
entry.

1. **A passive autofill/prefetch adoption on first load is a FULL adoption.** If the
   cookie-driven prefetch that runs on initial checkout load resolves to a sole
   trader and populates the company name, it must also set the mode to sole-trader
   and render the "select a different sole trader" affordance immediately — not defer
   either until some later explicit user action. Writing only the display value is
   the bug, and it is silent: the name looks right, then reopening the dropdown shows
   "Registered company" selected, and a signup completing later is dropped on the
   floor because the mode it needed was never set. The trap is structural, so look
   for it by call site rather than by symptom: on WooCommerce the restore path wrote
   straight to the capture layer and bypassed `setCompany()` — the ONE function that
   sets mode/adopted and syncs the link — so any path that can populate the company
   field must either go through that single function or set the same state itself.
   Corollary, same commit: once adopted, a LATER prefetch for a re-edited email must
   not revert the adoption. The email field stays editable by design; a non-match
   there means "the autofill cookie disagrees with a settled adoption", not "abandon
   sole trader". Adoption is a one-way latch until the buyer explicitly asks for a
   different company.

   **Open on PrestaShop — don't port the gap, and don't treat PrestaShop as the
   reference for this rule.** PrestaShop has no load-time autofill prefetch at all (its
   buyer lookup runs only from an explicit enrolment click or a signup completion), but
   it has the same shape of restore: the checkout manager seeds the confirmed company
   name and org number from the server on init, and a returning buyer's saved address
   carries an adopted sole trader's name plus its `TWO:` number. Nothing on that path
   renders the "select a different sole trader" element, which is the single source of
   truth for "adopted" (§0) — so on such a load the field looks right while the control
   is still in registered-company mode: reopening shows the wrong chip selected and a
   typable query. Not fixed as of this writing; the fix is the same shape as
   WooCommerce's — recognise a `TWO:`-prefixed seeded number on that path and enter the
   adopted state from it.
2. **Reopening the dropdown once adopted offers no free-text query.** The Sole trader
   chip shows as selected (§0), and the query input must not accept typed input —
   `readonly`, not `disabled` and not unbound: the click that opens the panel must
   still land, and the field must stay focusable and in the tab order. There is
   exactly ONE way to change company from that state: the explicit "select a
   different" affordance. That is deliberately two entry points into one call — the
   standalone link, and re-clicking the Sole trader chip — and re-clicking the chip
   must route through the IDENTICAL relaunch call the link uses. Not a no-op (an
   earlier round on both platforms made it one; Doug reversed that explicitly), and
   not a fresh enrolment either, which would re-mint tokens for an identity already
   adopted, and can re-adopt the same prefetched match with no popup at all — the
   opposite of what "select a different" means. Shared entry points need a shared
   re-entrancy guard: the relaunch opens the popup SYNCHRONOUSLY with no guard of its
   own, so without one a double-click reliably opens two signup popups.
3. **Clicking back to "Registered company" keeps the dropdown OPEN with focus in the
   query field.** Unlike the other two chips, this one is not a hand-off to another
   flow — it means "stay here, search normally" — so it must not close the panel, and
   the query field must become typable again in the same click. Implemented on
   PrestaShop (`1c1b3d7`, pinned by tests): the handler reverses manual-entry and
   cancels any enrolment, re-renders the chip selection (which is also what clears
   the `readonly` from rule 2), and focuses the query field, with no close call
   anywhere in it. One ordering trap, worth copying rather than rediscovering:
   cancelling the enrolment fires the same "flight settled" event that the keep-open
   spinner's own listener answers by CLOSING the panel, so that listener has to be
   unbound before the cancel can dispatch, or this click closes the very panel it is
   trying to keep open. **Not verified on WooCommerce as of this session** — its
   business-chip branch calls `setMode("business")` and leaves the widget's own
   open/close and focus behaviour to select2; check it against this rule rather than
   assuming it inherits it.

## 12. Chip visibility is PUSHED from the availability answer, never only pulled

The chip's gate is the registry's per-country answer, resolved asynchronously
(§1a). The control that draws the chip reads that answer at the moments IT
re-evaluates — panel open, address-form re-render, adoption — so an answer landing
after the panel is already open reaches nothing. Push it: the module that owns the
availability answer must tell the search control to re-sync its chip every time it
applies one. WooCommerce already did this; PrestaShop did not, which is why the chip
could be missing entirely for a supported country (GB, live-reported by Doug
2026-08-19) — the address step has no server-rendered answer to adopt, so on a first
visit every panel opened inside the round trip painted with no chip and nothing added
one afterwards.

Two caching rules fall out of the same bug, and they generalise to any platform that
caches this answer:

- **`success: false` is not an answer.** The availability endpoint replies HTTP 200
  with a JSON error body for a stale/absent ajax token or an unknown action, so it
  never reaches a transport-failure branch. Flattening that into "not available" and
  caching it turns one expired token into a chip that is gone for the cache's whole
  lifetime, with nothing that re-asks. Treat a declined request exactly like a
  transport failure: cache nothing, let the next trigger re-ask.
- **Never persist a NEGATIVE answer across page loads.** A cross-load cache exists so
  an available chip paints without waiting out a round trip; there is nothing to paint
  faster for a country with no chip, so storing "no" buys nothing and costs a full TTL
  of staleness after the answer behind it changes (a country being enrolled, an
  environment being fixed). REMOVE the stored entry on a negative rather than skipping
  the write — skipping leaves an earlier "yes" standing after the country stops being
  eligible, the same bug pointing the other way. Keep caching the answer in memory for
  the page's life either way, so no extra request is made per page.
