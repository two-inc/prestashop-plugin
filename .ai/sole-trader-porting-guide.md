# Sole-trader / company-search: porting guide (PrestaShop → Magento / WooCommerce)

Source of truth: `two-inc/prestashop-plugin` `staging`, PRs #145–#174, and
`two-inc/woocommerce-plugin` `staging`, PRs #456–#486 (TWO-40). Claims below were
re-verified against PrestaShop `58795d6` and WooCommerce `7b3fd60`. This guide is the
distilled design + gotcha list for reimplementing the same behavior on another
platform. Read it before writing code — most of the entries exist because a naive
first attempt got it wrong and had to be corrected, often more than once, sometimes
reversing an earlier decision entirely.

PrestaShop is the reference for the dropdown/chip design and the address model
(§0–§2, §11). WooCommerce is the reference for several rules PrestaShop does not
implement at all (§11.1, §12, §13, §16) and is ahead on the popup and widget-teardown
work (§14, §17). Every entry names which platform it was proven on; do not assume
either one is the reference for everything.

## 0. The mode chips are DOM children of the search dropdown, not a separate widget

**This was missing from earlier drafts of this guide, and its absence caused the same
wrong port to happen twice independently** (once in an initial WooCommerce audit that
called existing tile-only placement "already correct," once in an unrelated from-scratch
reimplementation that reasoned "the guide specifies how each placement must read, not
that a placement must be added" and left the chips as a separate persistent tile
element). Both were wrong for the same reason: the guide talked about *placement*
(§1 below) without ever stating the one fact that actually constrains chip markup.

**What getting this wrong cost, concretely:** WooCommerce PRs #456–#464 were built
from the placement prose without this section, and the whole batch was then reverted
wholesale to the pre-#456 tree (#466, `6673917`) and reimplemented from scratch
(#467) rather than patched, because the chips-as-a-fixed-tile-element decision was
load-bearing for everything layered on top of it. Nine merged PRs discarded.

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
  class change, not a visibility mechanism. No `aria-selected`, no `aria-pressed`, no
  `data-*` carries it.)
- Consequence for porting: **do not build the chips as an always-visible element
  fixed in the payment tile or address form.** Whatever placement §1 resolves to
  (a.1/a.2/a.3), the chips render *inside that placement's own search-dropdown panel*,
  as children of one intermediate wrapper, appear only while it's open, and disappear
  when it closes — exactly like the search results they sit next to. A platform whose
  company-search control has no dropdown panel concept at all needs one added; that is
  in scope for this port, not an acceptable reduction of it.
- **The chip group fills the row's full width.** Give each chip a half-row flex basis
  rather than letting it size to its own content, which leaves visible slack to the
  right and lets the arrangement fall out of the host theme's column at some widths
  (WooCommerce `f8ca174`). The 2-up-then-one-full-width layout is CSS, not markup
  nesting.
- The old "my company is not on the list" link-style fallback is NOT additional UI
  alongside the chips — it no longer exists as separate copy on PrestaShop (confirmed:
  zero occurrences of "not on the list" or "my company" anywhere in the page, in any
  state). It has been fully absorbed into the third chip, class
  `two-company-not-listed` (the class name retains the old wording), visible label
  "Enter manually." A port that keeps its own platform's old fallback link *and* adds
  the chips as a second, separate mechanism has not implemented this design — it has
  implemented two overlapping ones.
- **Chip selection must survive a reopen — was a live PrestaShop bug, now FIXED
  (`1c1b3d7`), so port the fixed behaviour, not the old one.** Reopening the panel
  used to reset `--selected` to "Registered Company" every time, whatever was last
  chosen. The root cause is worth knowing because the wrong fix is tempting: the
  reopen handler reset the mode unconditionally, reasoning that cancelling an
  in-flight enrolment on reopen had already reversed the only other way the mode
  could be `sole_trader`. That is true of an in-flight ENROLMENT and false of an
  already-ADOPTED identity — cancelling a signup in progress does not un-adopt a
  completed one. Derive the reopened selection from whether an identity is adopted,
  not from a mode variable the reopen path is free to clobber. Full rules in §11.
- **Being inside the panel means every chip click is a `focusout`/`focusin` pair the
  panel's own close machinery reacts to**, so any behaviour the panel hangs off "focus
  left me" is a behaviour the chips silently opt out of. That is not a detail of the
  close handler; it is a consequence of this section's nesting, and it cost three chips
  the popup-lifetime decision — see §14's gesture rules.
- **Chip labels are sentence case on both platforms** — "Registered company", "Sole
  trader", "Enter manually" (`1c1b3d7` aligned PrestaShop onto WooCommerce's existing
  wording; WooCommerce `f8ca174` then fixed its own last title-cased straggler,
  "Enter Manually"). Not Title Case, on any platform. Renaming a label can break
  translation lookup outright — see §10.
- **The "select a different sole trader" element is OUTSIDE the dropdown, not inside
  it — this corrects an earlier wrong edit to this guide.** It is a `<button>`
  (`.two-company-select-different-sole-trader`), appended as the LAST child of the
  outer field wrapper (`.two-company-field-wrap`), landing as a following SIBLING of
  `.two-company-dropdown` — never a descendant of the dropdown panel or of
  `.two-company-mode-chips`. It renders only after a sole-trader identity has been
  adopted, in normal block flow below the company field, deliberately never
  overlapping the (possibly-open) dropdown or the org-number hint that shares the
  same wrapper. There is NO equivalent element for an adopted *registered* company at
  all — re-searching a registered company is done by clicking the company input
  itself, which reopens the dropdown; only the sole-trader case gets an explicit
  standalone "pick a different one" affordance.
- **Exactly one source of truth for "adopted" — but the arrow points different ways on
  the two platforms, so copy the invariant, not the mechanism.** PrestaShop reads the
  presence of that button as the answer (`isSoleTraderAdopted()`, `TwoCompanySearch.js`),
  deliberately rather than keeping a second flag that can drift. WooCommerce already
  has an explicit mode and derives the button's visibility from mode + tokens
  (`9d7a952`, which also *removed* a DOM probe of the org-number field from that gate,
  per Doug's ruling). Both are fine. Two independently-maintained answers are not.
- **The element's ancestors can hide it out from under you.** On WooCommerce the link
  lived inside the native company field's wrapper, which the display logic hides
  whenever the search widget rather than the native field is what's shown — the button
  was toggled visible inside a `display:none` ancestor (`9d7a952`). It has to follow
  whichever field is actually visible.

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

**A platform may not have all three.** WooCommerce has no shipping-side company
handling whatsoever — zero occurrences of `shipping_` in its checkout JS at
`7b3fd60` — so a.1 does not exist there and there is nothing to keep in step with
it. Check this before building the sync in §2; the answer changes how much of it is
real work.

**Security note, already investigated and disproven on PrestaShop, don't re-litigate
it per-platform without cause:** the token-mint call takes no country parameter at
all — country only feeds an availability-gate lookup, which has no security
consequence either way (a client can already query availability for any country
directly). Preferring the live in-page value over a committed one is a pure
correctness win, not a security tradeoff.

## 2. Two-address model: editable + synced, never locked

**Applies only where the platform actually has two company-carrying addresses.** On
WooCommerce, rules 1–5 below are N/A for the reason in §1 (no shipping-side company
at all — no two-address model, no mirror, nothing to pin). Only rule 6
(field-routing) and rule 7 (requirement scope) were real gaps there.

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
   - **A mirror scope that spans two addresses must be refused, not resolved**
     (`4c61223`), and a form with no resolvable scope is not the same as no form
     (`9b01f24`). Recognise an address block by more than its id (`53fe115`).
   - **The mirror must not touch a delivery form that is not part of the order** at
     all (WooCommerce `9c8e7dd`, before the revert; the rule survives the rebuild).
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

**A `TWO:` prefix on a restored value is also a signal, not just data** — it is how a
returning buyer's already-adopted sole trader is recognised on a page load. See §11.1.

## 4. Cross-platform persistence check (why this matters)

WooCommerce and Magento already persist org number + company name durably,
independent of session/cookie state: WooCommerce via order meta (`company_id`,
unprefixed key, `TWO:` values stored raw) + user meta; Magento via a genuine EAV
attribute on the customer-address entity (varchar(255), no validation) + the order
payment's `additional_information` JSON blob. Neither platform's admin order screen
actually surfaces it, though (matches the corrected PrestaShop state).

On WooCommerce, the empty-org-number-on-PUT bug class §6 describes is structurally
impossible and was pinned by a regression test rather than fixed: the order-edit
compose path carries no company at all, and the meta save returns early with an order
note (`713e5a0`, re-pinned in the rebuilt port). Verify the equivalent on any new
platform rather than assuming either answer.

**PrestaShop's own equivalent (§6):** two new columns on the module's own order-keyed
table, following the exact precedent already in that table for a different
"checkout-time value needed later at admin/webhook time with no session available"
problem (`two_day_on_invoice`/`$storedTerm` in `getTwoUpdateOrderData()`). If the
target platform already has durable order-scoped storage (it does, per above) —
reuse it, don't build a parallel mechanism.

## 5. Write-back state machine — the recurring root cause

This was the single most repeated bug source on this feature. Any platform's
sole-trader implementation needs the equivalent of:

- **A pairing tag**, recording which org-number selection a given company-name value
  was chosen under. On the next input/change to the company field, if the tag is
  absent or doesn't match, wipe the org number and any dependent state — this stops a
  stale org number silently surviving a retype.
- **A single write-path helper** that sets the company-id/org-number field AND its
  pairing tag atomically, in one call. Any code that sets the org-number field
  without going through this helper gets silently wiped by the guard above on the
  very next interaction. This bit multiple PRs before the pattern was fixed into one
  shared helper.
  - **Frame it as one helper per CAPTURE, not per write.** On WooCommerce only 4 of
    16 write sites should route through it: the CLEAR paths each encode ordering
    constraints a shared helper cannot express without a flag per caller (the clear
    gates its name-wipe on search mode; the manual-entry enter/exit pair must toggle
    field visibility AFTER widget teardown; the page-load restore writes DOM and
    deliberately never the record, or it would fire order-intent on page load for
    every returning buyer). Forcing all 16 through one helper is a worse bug than the
    one it fixes.
  - **The pairing witness must not leak into the submitted company value set**
    (`69e3c67`) — it is bookkeeping, not buyer data.
- **A provenance marker**, separate from the pairing tag, recording that a given
  field's current value was written by the plugin (vs typed by the buyer) — needed
  by the pin/mirror logic in §2 to distinguish "still what we wrote" from "buyer
  edited this."
  - **Treat this as required even where §2's mirror does not exist.** An earlier
    WooCommerce audit reasoned it was N/A there (its only named consumer was the
    mirror, which WooCommerce does not have) — the rebuilt port ships it anyway, with
    its own consumer, and both attributes are on the capture fields at `7b3fd60`.
    "Nothing reads it yet" is not the same as "nothing should".
- **The write-back function must NOT be gated on the same "is address-lookup enabled"
  switch that gates an ordinary company-search selection's address write.** On
  PrestaShop, `adoptSoleTraderBuyer()` initially routed through the same
  `autoFillAddress()`/`writeOrganizationToAddressIdentifiers()`/`autoFillRegion()`
  methods an ordinary search-result selection uses — all three early-return when that
  switch is off (which it legitimately is whenever company-search isn't mounted in
  the address area). Sole-trader signup completion must write regardless of where
  company search is mounted; add an explicit bypass parameter rather than trying to
  make the switch context-aware.
- **An adoption that threw must report its own cause, not a generic "no company
  found"** (`b1be7c2`, `8de66b0`). A real failure reported as a missing company sends
  the next reader — and the buyer — to the wrong place entirely.
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
  - The inverse also bit: an adoption that pointed the buyer at a destroyed picker
    read the captured name back EMPTY, so no order intent fired at all
    (WooCommerce `3620d5b`). Whatever surface the design claims holds the company
    name, every reader must actually read that one.

## 6. Order-scoped persistence pattern (Option A + C)

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

**A refused schema change must omit the column, not lose the row it was carrying**
(`a1b35c9`). An `ALTER` that fails on some merchant's DB must degrade to "this value
is not persisted here", never to a dropped payment record.

## 7. UX additions, implement alongside the core flow, not as an afterthought

- **In-flight spinner:** while the sole-trader autofill/token-fetch/signup-check
  round trip is running, keep the company-search control OPEN (don't close it) and
  show a spinner over the company-NAME field — not a separate overlay, and not in
  the dropdown's query field. Wire it to the real async duration (resolve on an
  actual "flight settled" event fired from every terminal branch of the async call
  graph — success, failure, retry-exhausted, abandoned), never a fixed timeout.
  Adversarial review on this exact feature found real races on every iteration
  (stuck-forever spinners on two different abandon/retry paths, a missing
  re-entrancy guard causing double signup popups, a guard released too early) —
  budget for multiple review rounds, don't expect to get this right in one pass.
  - **The NAME field, not the query field, and this was arrived at the long way
    round.** Earlier rounds put it in the query input, which §1 does settle as
    where an in-field spinner on ordinary company search belongs. It cannot be
    where the sole-trader one belongs, for two independent reasons: selecting the
    Sole trader chip hides that whole row immediately (§11 rule 2), so the spinner
    has nowhere to paint and an earlier round had to un-hide the row for the flight
    — which reintroduced the very bug rule 2 exists to fix; and the "select a
    different sole trader" flow opens no dropdown at all, so it had no query field
    to use and showed no spinner whatsoever. The name field is on screen in both
    flows and is where the value being fetched is going to land. PrestaShop
    `doug/two40-soletrader-spinner-rehome`.
  - **"Settled" means the popup CLOSED, not that `window.open()` returned**
    (PrestaShop `5651374`, live-reported). The spinner was clearing the instant the
    window was handed to the browser, leaving the buyer's whole signup happening
    behind an idle-looking checkout. Poll the popup handle's `.closed`, and make every
    exit path (popup close, cancel, teardown) stop that poll so no interval leaks.
  - **…and popup-closed is still not the END of it — the WRITE is** (Doug, live,
    PrestaShop `doug/two40-soletrader-spinner-rehome`). "Complete" is: the popup is
    gone, AND the post-popup buyer lookup has fired and resolved, AND the company
    name/number have been written to every field and variable that holds them.
    Those are separate moments, and the popup one usually comes FIRST: the hosted
    flow closes its own window as soon as it has posted its completion message, so
    a 500ms `.closed` poll routinely wins the race against the lookup → save →
    write chain that message starts, and the spinner dropped with the field still
    empty. Extend the existing settle gate rather than adding a second signal
    beside it — hold the dispatch while a lookup or a write-back is outstanding,
    and let whichever finishes last do the dispatching. Do NOT reach for the
    "adoption succeeded" event as the spinner's clear signal instead: it fires only
    on the success path, so every failure and abandon would spin forever.
    A cancel/abandon must be able to FORCE the dispatch past that gate, since the
    generation bump it performs has already disowned whatever is still in the air.
  - **Focus returning to the checkout page must take the POPUP down with the
    spinner and the panel** (Doug, live, PrestaShop
    `doug/two40-soletrader-spinner-rehome`). All three are one abandon, and it is
    easy to implement a subset of it: PrestaShop stopped the spinner and closed the
    dropdown on that path but left the hosted popup on screen. The trigger already exists if the
    panel has a deferred close-on-focus-leaving handler (§1) — hang the popup close
    off THAT decision point, not off the generic "panel closed" path, which also
    covers a completed selection and a platform re-render and would slam a live
    popup shut. It must sit AFTER that handler's own guards: a focus-out caused by
    clicking one of the panel's own chips puts focus back inside the panel and
    that chip's handler owns the flow. Closing is the opener's privilege however
    cross-origin the popup is, and is a no-op on a window that has already gone —
    so a buyer who hand-closed it, and a hosted flow that closed itself the moment
    it posted its completion message, both need no special case. Leave the
    `.closed` poll to clear the handle and dispatch the settle, so that stays a
    one-owner job. Do NOT fold this into the resumable cancel/abandon call (§14's
    "still glancing around" case) — that one runs on every reopen of the search
    control and must leave the popup alone.
- **Tokens must already exist when the chip is clicked.** A chip click has exactly two
  allowed outcomes — populate a company, or open the signup popup — and a fallback
  note/link is neither (WooCommerce `df1aaa1`). Minting inside the click handler puts
  `window.open()` behind an async callback (popup-blocker bait) and opens four races
  review had already closed on the other path: mint failure, country change mid-mint,
  an email typed mid-mint, and a double click starting two mints. Mint when the option
  becomes available instead, which removes the async branch from the gesture entirely
  (`15de0f8`). See §15 for keeping those tokens alive afterwards.
- **The manual-entry chip must not be able to become a dead end.** WooCommerce's
  removed the chip synchronously and deferred the actual mode switch a tick, but that
  switch refuses to run while sole-trader mode is active or still deciding — so both
  refusals landed after the chip was already gone, leaving the buyer with no chip and
  no manual mode (`27a0532`). A settled sole-trader mode must switch to business
  first; a still-deciding one must drop the click and KEEP the chip.
- **"Select a different sole trader" link** — placement per §0's corrected findings,
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
- **An in-flight replacement flow must be cancelled on country change and on
  teardown** (`2954c07`, `e541136`), not just on completion.
- **Popup window size:** `700×805` (width×height) — not narrower; a too-narrow popup
  clips the hosted signup flow's own layout. One `window.open()` call site, shared by
  the first-time enrolment and the replacement flow (`872dd18`).
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
  - Evidence that no overlay work is needed on either platform: the existing
    brand-overlay plugins never hook the signup-URL filter at all (zero sole-trader
    hits across their live branches) and brand purely via `checkout_url_template`,
    which the base plugin's own signup-URL composer already applies mode-aware. A port
    should expect to change nothing in its overlay.

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

**The re-entrancy guard around that path must be held for the whole retry wait**, not
released when the first attempt returns — the hosted flow can 404 briefly after a
successful OTP while the identity settles (`76c5021`).

## 9. Local-dev tooling

Independent dev-mode env-var overrides for all three service hosts a plugin talks
to — checkout/merchant API, merchant portal, and the hosted checkout-page app
(sole-trader signup + company search) — each falling back independently to its
platform-appropriate default, and ALL gated so a production instance can never
accidentally honor one even if the env var somehow leaks into its process
environment. Magento already had this (`TWO_API_BASE_URL`/`TWO_CHECKOUT_BASE_URL`,
gated on developer mode, documented in its README); PrestaShop added it in #163
(`e8fb11a`) and WooCommerce in #458, rebuilt as `242fbff` — so this is now a
three-platform pattern, not a thing to invent.

Two pieces of the harness are worth porting with it, because they are what makes a
misconfigured override visible instead of mysterious:

- **`make install` / `make run` print the resolved hosts**, plus store URL and admin
  credentials, as one status block — and both targets print the SAME block
  (PrestaShop `67f8791`, WooCommerce `464aa79`/`f58dcc2`, parity closed in `9554960`).
- **The checkout line reports the actual signup-page URL**, composed by the same
  helper the runtime uses, not the raw checkout host (`9554960`). A raw host looks
  fine while the path it will actually open is wrong.
- Auto-detect the environment rather than requiring it to be passed (`4e96bb6`).
- Watch out for the probe leaving cache directories root-owned (`33b8f45`) and for
  needing to pre-create bind-mount dirs so a clean doesn't need sudo (`593f63d`).

For a remote/non-local shop needing to reach a developer's laptop-hosted
checkout-page specifically (not the API/portal, which the shop's own server-side
process can usually reach via a normal Docker network alias): the browser is what
opens the popup, so the override host has to be one the BROWSER can resolve — an FRP
reverse tunnel exposing the local checkout-page dev server works and is what was
used; don't assume `localhost`/`host.docker.internal` alone is sufficient
once the shop itself is remote (e.g. GKE-hosted), since neither resolves anything
useful to a browser pointed at a remote domain. Kill any such tunnel by repo, not by
the current checkout's PID file, or a second worktree's tunnel survives and serves
the wrong build (`2b99c2b`).

## 10. Process notes, not code, but save real time

- **Never trust a fix's own test as proof it worked live**, especially for anything
  visual/timing-sensitive. A "fixed" chip-selection PR shipped with its own
  regression test passing while the fix did nothing in a real browser (§5's paint-
  timing entry) — caught only by live re-testing after merge.
- **A round of adversarial review whose only findings are artifacts of the PREVIOUS
  round's own fix is oscillating, not clean** — don't merge on it, run another round
  against the latest fix specifically. If you are re-tuning the same predicate for a
  fourth time, the design is wrong, not the predicate (§14 is the worked example, and
  the popup-stacking guard that had to be reverted outright — `989a765` — is what
  happens if you keep going).
- **Defects that no single round can see.** Two rounds can each be correct and clean
  and still compose into a live bug: PrestaShop's reopen-in-sole-trader-mode round and
  its blank-the-term-on-the-way-in round together left result rows painted and
  clickable for a term the field no longer held (`0f1f937`), and three WooCommerce
  rounds composed into an adoption pointing at a destroyed picker (`3620d5b`). Run a
  final review of the WHOLE branch against the merged behaviour, not only round-on-round.
- **Renaming a user-visible string can break translation lookup, not just wording.**
  PrestaShop keys catalogue entries by the md5 of the source string, so the
  sentence-case chip rename silently orphaned both translations and reddened the
  translation-coverage spec on every gated locale — on the BASE branch, not the one
  that made the change (`2fc6152`). Re-key, don't re-translate. Check the target
  platform's own keying before renaming any label.
- **Any jsdom/virtual-DOM test whose assertion is "nothing happened" must flush with a
  macrotask** (`new Promise(r => setTimeout(r, 0))`), never a counted number of
  microtask ticks. A tick-counting flush sits exactly on the edge of the current
  promise chain and silently rebases when the chain changes: deleting a `postMessage`
  origin check outright left all 13 tests green, including the one named "ignores a
  message from any other origin". It asserted a state not yet reached, not a state
  refused.
- **Listener accumulation makes a suite structurally unable to see the bug it is
  for.** A module-level "already bound" flag re-evaluated per test, with
  `addEventListener` targeting a `window` that persists for the whole file, ran 1,
  then 2, then 3 handlers per dispatch — and every assertion still passed because each
  stale handler wrote the same values. The suite had N-fold adoption by construction
  and so could never detect a double-adoption bug. Intercept `addEventListener` to
  capture exact handler refs, remove them in teardown, **and restore the
  interception** or each setup wraps the previous wrapper.
- **Live-verifying against a cached/CDN'd asset needs a cache-busted URL** — a bare,
  unversioned URL request can return a stale cached response even when the real
  deployed asset is already correct, producing a false "not deployed yet" read.
- **Never substitute your own hand-rolled request for an unobserved one and report its
  status as the application's.** A credential-less `fetch()` from page context
  returned 401 where the module's own call returned 404, and the 401 was written up as
  the module's blocker; the endpoint turned out to be a cookie probe where doing
  nothing on a 404 is correct. The whole thread was a non-finding.
- Every "obvious" root cause on this feature turned out to need at least one
  correction once tested against real production-shaped data (a real buyer's
  existing address, a real merchant's registry answer, a real popped-open browser
  window) — budget live-verification time into every port, not just the initial
  unit-test pass.

## 11. Adoption is a MODE, not a populated field

All three rules below are Doug's, from live testing on 2026-08-19/20. They are one
design, not three fixes: **once a sole trader is adopted, that is the state of the
control**, and every surface has to agree with it. Rules 2 and 3 were each shipped
twice, because the first round of each was an incomplete reading of the rule rather
than a wrong implementation of it — read both corrections before implementing either.

1. **A passive autofill/prefetch adoption on first load is a FULL adoption.** If the
   cookie-driven prefetch that runs on initial checkout load resolves to a sole
   trader and populates the company name, it must also set the mode to sole-trader
   and render the "select a different sole trader" affordance immediately — not defer
   either until some later explicit user action. Writing only the display value is
   the bug, and it is silent: the name looks right, then reopening the dropdown shows
   "Registered company" selected, and a signup completing later is dropped on the
   floor because the mode it needed was never set. The trap is structural, so look
   for it by call site rather than by symptom: on WooCommerce the restore path wrote
   straight to the capture layer and bypassed the ONE function that sets mode/adopted
   and syncs the link, so any path that can populate the company field must either go
   through that single function or set the same state itself. **Recognising a restored
   `TWO:`-prefixed org number is the trigger** — there is no other signal on that path
   (`48edd08`).

   Corollary, same commit: once adopted, a LATER prefetch for a re-edited email must
   not revert the adoption. The email field stays editable by design; a non-match
   there means "the autofill cookie disagrees with a settled adoption", not "abandon
   sole trader". Adoption is a one-way latch until the buyer explicitly asks for a
   different company.

   **Second corollary, and it needed its own fix: cover the GUEST restore too.**
   WooCommerce's first pass gated the whole restore block — its own DOM fallback
   included — on a server-side user-meta echo that only exists for a signed-in user.
   A guest checkout whose company fields the platform (or the plugin's own session
   pass) had already restored skipped all of it, reproducing the exact original bug
   for the most common case (`9ad8ab5`). Two rules fall out: run the restore after
   EVERY pass that can supply a company, including the session/guest one; and take
   both halves of the pair (name and number) **from one source** — echo or DOM — never
   one half from each.

   **Open on PrestaShop — don't port the gap, and don't treat PrestaShop as the
   reference for this rule.** PrestaShop has no load-time autofill prefetch at all (its
   buyer lookup runs only from an explicit enrolment click or a signup completion), but
   it has the same shape of restore: the checkout manager seeds the confirmed company
   name and org number from the server on init, and a returning buyer's saved address
   carries an adopted sole trader's name plus its `TWO:` number. Re-verified at
   `58795d6`: `renderSelectDifferentSoleTraderLink()` has exactly one caller,
   `adoptSoleTraderBuyer()`, so nothing on the restore path renders the element that IS
   the adopted state there (§0) — on such a load the field looks right while the
   control is still in registered-company mode: reopening shows the wrong chip selected
   and a visible, typable query. Still not fixed; the fix is the same shape as
   WooCommerce's above.
2. **Reopening the dropdown once adopted offers no free-text query.** The Sole trader
   chip shows as selected (§0), and the query input is **not rendered at all**.

   **`readonly` is NOT an acceptable reading of this rule — it was an incomplete
   implementation of it, on BOTH platforms.** The first round on PrestaShop
   (`1c1b3d7`) and on WooCommerce (`48edd08`) each made the field readonly and left it
   on screen; Doug's correction on re-test was verbatim: "the field should not be
   *visible*. I did not tell you it was editable, I told you it was visible." A search
   box that is painted but inert reads as a search box that has broken, which is worse
   than one that is absent. Hide it — `display:none` or the `hidden` attribute, **not**
   `visibility:hidden`/`opacity:0`, which leave it in the tab order for a keyboard
   buyer to land on something they cannot see. Keeping `readonly` on as well costs
   nothing and is worth it as defence in depth against a programmatic write, but it
   satisfies nothing on its own. Fixed on PrestaShop in `af6704a` and on WooCommerce
   in `f8ca174`; **this corrects an earlier version of this guide, which recorded the
   WooCommerce half as an open gap.**

   Three consequences that are easy to miss, all found by review rather than by
   testing the happy path:

   - **Hide the query field's whole ROW, not the input.** The ordinary live-search
     spinner is an absolutely-positioned sibling *inside* that row, so hiding the
     input alone collapses the row to zero height and strands that spinner at its
     top edge.
   - **Gate the hide on the selected chip and NOTHING ELSE** (Doug, live). It has to
     take effect on the click that selects the chip, synchronously, with no reopen
     required — so drive it from wherever chip selection is rendered, not from the
     panel's open handler, or it will look correct in every test that closes and
     reopens and be wrong for the buyer who never closes anything. An earlier round
     added a second condition — stand the hide down while a sole-trader flight is in
     progress, because the in-flight spinner then lived in this very field — and
     that condition WAS the bug: the chip click hid the row and un-hid it in the
     same gesture, so a row the buyer had been told would go stayed up for the whole
     round trip. The spinner belongs on the company-name field instead (§7); with it
     gone, no second condition is needed and none should be added back.
   - **Something else in the panel has to take focus on open.** The open path focuses
     the query field; `.focus()` on a `display:none` element silently does nothing,
     leaving focus on the company-name field — *outside* the panel, and the panel is
     where Escape-to-close and close-on-focus-leave are bound. A keyboard buyer would
     have opened a panel they cannot close. Focus the selected chip instead, with the
     always-visible Registered company chip as the fallback for the one state where
     the Sole trader chip is itself hidden (adopted, then the registry stops offering
     that country).
   - **Clear the RESULT ROWS as well as the term.** Dropping the query text does not
     empty the list it produced: jQuery UI's `response([])` only closes the menu, which
     hides it without emptying it — and sole-trader mode is the one mode that keeps the
     panel OPEN (manual entry closes it), so registered companies stayed painted and
     clickable next to a search row that was not even rendered (`0f1f937`). Reachable
     on the main flow — search, then decide you are a sole trader — and on every reopen
     while adopted. Clear by re-running the same open-for-current-term path the panel
     already opens with, so each search engine answers through its own existing
     "no rows in this mode" branch rather than gaining a second way to empty the menu.

   Drop any query term on the way out, too: it describes a company the buyer then did
   not pick, and restoring it above results that no longer match it is worse than an
   empty field.

   There is exactly ONE way to change company from that state: the explicit "select a
   different" affordance. That is deliberately two entry points into one call — the
   standalone link, and re-clicking the Sole trader chip — and re-clicking the chip
   must route through the IDENTICAL relaunch call the link uses. Not a no-op (an
   earlier round on both platforms made it one; Doug reversed that explicitly), and
   not a fresh enrolment either, which would re-mint tokens for an identity already
   adopted, and can re-adopt the same prefetched match with no popup at all — the
   opposite of what "select a different" means. Shared entry points need a shared
   re-entrancy guard: the relaunch opens the popup SYNCHRONOUSLY with no guard of its
   own, so without one a double-click reliably opens two signup popups (§14).

   **"The same call" means the same in-flight state too, not just the same
   function** (Doug, live). Both entry points show the same spinner, in the same
   place, for the same §7 duration. The first implementation shared the relaunch
   function but gave each entry point its own loading flag and its own settle
   listener under its own namespace — with the result that the standalone button
   showed no spinner at all, and a chip click could open a second hosted popup over
   a replacement flow already in flight, which is exactly what §14 forbids. One
   flag, one listener, one spinner, for both. The single genuine difference is the
   dropdown's open/closed state — the chip click leaves it open throughout, the
   button never had one — and the resolution is to close the dropdown at
   flow-complete **only if it is open**, a no-op otherwise. Not two flows; one flow
   and one conditional. (Consequence worth stating: whatever renders/re-renders the
   "select a different" element must NOT release that shared flag as a
   belt-and-braces measure, because the successful adoption re-renders it
   *mid-flight* — that release would drop the spinner just before the write it is
   waiting for.)
3. **Clicking back to "Registered company" keeps the dropdown OPEN with focus in the
   query field.** Unlike the other two chips, this one is not a hand-off to another
   flow — it means "stay here, search normally" — so it must not close the panel, and
   the query field must become typable again in the same click. Implemented on
   PrestaShop (`1c1b3d7`, pinned by tests in `40ec6d4`): the handler reverses
   manual-entry and cancels any enrolment, re-renders the chip selection (which is also
   what brings the query field back from rule 2's hide, readonly and all), and focuses
   the query field, with no close call anywhere in it. One ordering trap, worth copying
   rather than rediscovering: cancelling the enrolment fires the same "flight settled"
   event that the keep-open spinner's own listener answers by CLOSING the panel, so
   that listener has to be unbound before the cancel can dispatch, or this click closes
   the very panel it is trying to keep open.

   **Open on WooCommerce, verified at `7b3fd60` — this replaces an earlier "not
   verified" note.** Its Registered-company chip binds the mode switch directly and
   returns; nothing in that handler opens the panel or focuses a query field. The
   open-and-focus behaviour exists in a *different* entry point (`reopenSearch()`,
   reached by clicking the captured field), so the fix is to route the chip through
   the same call, not to write a second one. Its own guard is worth keeping as-is
   though: refuse the click while the outcome is still DECIDING, not for the wider
   "busy" window — once adopted, only the popup-close poll hasn't caught up, and
   refusing clicks for that stretch is a UX regression, not a safety guard.

## 12. Chip visibility is PUSHED from the availability answer, never only pulled

The chip's gate is the registry's per-country answer, resolved asynchronously
(§1a). The control that draws the chip reads that answer at the moments IT
re-evaluates — panel open, address-form re-render, adoption — so an answer landing
after the panel is already open reaches nothing. Push it: the module that owns the
availability answer must tell the search control to re-sync its chip every time it
applies one. WooCommerce already did this; PrestaShop did not, which is why the chip
could be missing entirely for a supported country (GB, live-reported by Doug
2026-08-19, fixed `40ec6d4`) — the address step has no server-rendered answer to
adopt, so on a first visit every panel opened inside the round trip painted with no
chip and nothing added one afterwards.

Three caching rules fall out of the same bug, and they generalise to any platform that
caches this answer:

- **`success: false` is not an answer.** The availability endpoint replies HTTP 200
  with a JSON error body for a stale/absent ajax token or an unknown action, so it
  never reaches a transport-failure branch. Flattening that into "not available" and
  caching it turns one expired token into a chip that is gone for the cache's whole
  lifetime (24h, on PrestaShop), with nothing that re-asks. Treat a declined request
  exactly like a transport failure: cache nothing, let the next trigger re-ask.
- **Never persist a NEGATIVE answer across page loads.** A cross-load cache exists so
  an available chip paints without waiting out a round trip; there is nothing to paint
  faster for a country with no chip, so storing "no" buys nothing and costs a full TTL
  of staleness after the answer behind it changes (a country being enrolled, an
  environment being fixed). REMOVE the stored entry on a negative rather than skipping
  the write — skipping leaves an earlier "yes" standing after the country stops being
  eligible, the same bug pointing the other way. Keep caching the answer in memory for
  the page's life either way, so no extra request is made per page.
- **If you also have a "only write when it differs from what's stored" guard, compare
  per storage SHAPE, not against the answer.** Those two rules interact badly: once a
  negative is *removed* rather than written, a stored negative cannot exist, so
  comparing the answer to the stored value always disagrees and every negative does a
  redundant synchronous `removeItem` — on every container swap, undebounced, which is
  exactly the traffic the guard exists to absorb (`090755f`). A negative has work to do
  only when there is an entry to remove.

## 13. An adopted sole trader is a SELECTION in the search control, not a second capture shape

Once adopted, a sole trader must look to the rest of the code exactly like a
registered company that was just searched for and picked. Do not destroy the search
widget, do not swap the display over to a plain native readonly field, do not build a
parallel "captured fields" rendering. PrestaShop got this right by accident of
construction — `adoptSoleTraderBuyer()` never touches its own search field — and
WooCommerce diverged, which produced a whole class of bugs rather than one.

Doug's framing is the spec: **clicking into the company field reopens the SAME
dropdown whatever mode you are in.** Only manual entry differs, via its own
affordance.

- The symptom that exposed it was "clicking the company field after adopting refuses
  to reopen the search". The cause was architectural, not a guard condition: adoption
  destroyed the widget and swapped in a readonly native field, while registered-org
  mode never destroyed anything (a click there just re-triggers the widget library's
  own open handler), and a `reopenSearch()` mode-switch-and-rebuild dance existed only
  to paper over the difference.
- Shipped as two PRs because the area had 6+ documented oscillating review rounds
  behind it: #485 (`0b93055`) stops the destroy — the widget just closes and stays
  alive; then #486 (`004814f`) seeds the widget's own underlying `<select>` with a
  synthetic `<option>` for the adopted identity and selects it, reusing the exact
  mechanism the page-load restore already used, so the widget renders the adoption as
  its own selection.
- **The widget's select handler must stop silently dropping a pick made while
  adopted.** It used to, on a mode guard. Once the widget is the surface, a pick there
  IS the buyer choosing a different company: refuse it only while a flight or popup is
  genuinely still deciding, otherwise leave sole-trader mode in place and let the pick
  write through normally — do NOT route it through the business-mode switch, whose own
  destroy-and-rebuild blanks the very pick that was just made.
- **The one legitimate exception:** a merchant with company search disabled entirely
  has no widget to render through, so that configuration keeps the native-field swap.
  Read the merchant's *saved* setting for that decision, not the live flag (§16).
- Watch for stale comments after this change. At `7b3fd60` a chip-guard comment still
  claims "an adopted sole trader destroys the widget it lives in", which #485/#486
  made untrue.

## 14. One hosted popup at a time — and attribute its messages BY WINDOW

Two sequential Sole trader clicks stacked two hosted signup popups; the in-gesture
re-entrancy guard only covers re-entry within one gesture. Getting from there to a
correct design took four review rounds and one outright revert, and the shape of that
failure is the lesson:

- A guard keyed on "any outstanding flight" refused legitimate clicks whenever a stale
  prefetch was in the air, so a chip click resolved to neither outcome — reverted
  wholesale rather than given another epicycle (`989a765`).
- A guard keyed on the watcher record's `decided` flag refused a fresh launch for up to
  one 300ms poll cycle after the buyer hand-closed the popup (`538ca57`).
- Adding a `!win.closed` disjunct to fix that falsified the "at most one undecided
  record" invariant the ACCEPTED handler's `find(!decided)` silently depended on: with
  two undecided records the forward scan returned the STALE hand-closed one, so the
  wrong record was marked decided, the live popup stayed undecided and open (refusing
  the sanctioned post-accept re-signup for as long as the hosted flow left its window
  up), and a re-signup counter decrement was billed to a record that never owed one,
  stranding the count above zero and blocking every leave-sole-trader action
  (`7017dc5`).

**The fix that ended it removes the search rather than re-tuning the predicate.** Read
`event.source` from the posted message: the browser names the window that posted, and
a `WindowProxy` is reference-comparable across origins, so the popup identifies itself
and the number of undecided records stops mattering. Fallbacks — for a popup that
closes in the same turn it posts and arrives with a null source — scan newest-first, so
a relaunch always beats the stale record it opened over.

Rules that generalise:

- Track popups as **records** (`{id, isReconfirming, decided}`), not as a single
  handle plus flags. A single `this._popup` retargeted by a second `open()` orphans the
  first window untracked, and a buyer closing the original leaves the spinner stuck
  forever — PrestaShop's smaller version of the same bug, fixed by FOCUSING the
  existing popup instead of opening another (`06655fb`).
- Every decrement of a re-signup counter must **belong to exactly one owner**, gated on
  the receipt that actually settles a popup, so a replayed ACCEPTED cannot spend a
  second decrement against one increment.
- A deferred **mode revert must ask whether another popup is still ON SCREEN**, not
  whether it is undecided. A record that is decided but still visible (an accept that
  resolved to no buyer) had mode reverted out from under it, dropping the buyer's retry
  on the handler's own mode gate.
- Scope every outcome to the record that produced it, never to global adoption state
  (`5cfcbd1`): a prefetch match landing under a still-open first-time popup set the
  adopted flag while that popup was undecided.
- If you are writing round N+1 of a predicate over a list of popup records, stop and
  change the design. That is what this section is.

**Once there is exactly one popup, decide which GESTURE closes it — in each gesture's
own handler, never in the shared focus machinery.** Doug's rule: focus returning to the
checkout page closes the popup, and the ONE exception is clicking the Sole trader chip,
which means "give me that popup back" and must raise it to the front instead. Whichever
other chip took the focus closes the popup *and* still does its own job unchanged.

The trap is §0's fact — the chips are DOM children of the search panel — meeting the
panel's own deferred close-on-focus-leaving. That close is what owns the popup
(PrestaShop `928a84a`), and it cancels itself on any `focusin` back into the panel,
which is exactly what a chip click produces. So all three chips escaped the popup
decision, and which of them nevertheless closed it was decided by where its own action
happened to leave focus afterwards (PrestaShop #176 `8c7447f`):

- **"Enter manually" got the right outcome by accident** — it ends by focusing the
  company-name field *outside* the panel, which re-scheduled a close nobody asked for.
  Correct on screen, untested, and one refactor of that focus destination from breaking.
- **"Registered company" left the popup up** — it focuses the query field, *inside* the
  panel, so the close it needed was the one it cancelled.
- **The Sole trader chip resolved to NOTHING AT ALL** — neither closing nor raising. Its
  re-entrancy guard reads the in-flight spinner flag, and that flag stays true for the
  popup's whole lifetime, so a re-click was swallowed before anything could raise the
  window the buyer was asking for. The focus-the-existing-popup branch this guide already
  credits above (`06655fb`) was live and correct, and unreachable.

Rules that generalise:

- Each gesture states its own answer explicitly. Three cases that differ must be
  *structurally* distinct and readable as such, not separated by which one happens to
  move focus where. A correct outcome you cannot point at a line for is a timing
  accident with a good week.
- **Gate the popup close on the PAGE having focus (`document.hasFocus()`), not on where
  `activeElement` landed.** The rule is "focus came back to the *page*", so a focus-out
  to another window — the popup you just raised, or another application — must leave the
  popup alone. The first round of this fix cancelled only the close already pending when
  the chip was clicked, and the close that the *raise itself* provokes arrives after that
  handler has returned; what actually saved it was Chrome leaving `activeElement` on a
  clicked `<button>` across the window deactivation. Incidental browser behaviour holding
  up a spec rule. **Scope the guard to the popup decision only** — widening it to the
  panel's own close changes when the panel survives an app switch, which is a separate
  question and broke a pinned test when tried.
- **Mutation-test this class of fix; the tests lie otherwise.** Three of the first
  round's tests passed with the line they existed to pin deleted: two because letting
  the deferred close run lets "Enter manually" satisfy the assertion through the old
  accidental route, one because leaving focus on a control inside the panel means the
  earlier `activeElement` guard returns before the code under test. Assert the handler's
  own call *before* advancing timers, and put focus genuinely outside the panel.
- **Report the raise as a boolean** ("was there a popup to raise?"), so the same handler
  can fall through to an ordinary first-time launch when there was not. A void raise
  forces the caller to keep its own second opinion about whether a popup is open, which
  is the drift §0 warns about in a different register.
- **Close BEFORE the cancel — and make that ordering unreachable.** The cancel path
  deliberately nulls the popup handle, so a close attempted after it has no handle left
  and the window sits there orphaned. Do not leave the sequence to callers; see the
  atomic-operation rule below.
- **Wrap the `focus()`.** The hosted flow closes its own window the instant it has
  posted `ACCEPTED`, so `closed` flipping between the check and the call is a real
  interleaving, not a theoretical one. Nothing to raise then and nothing to report; the
  close poll still solely owns clearing the handle and dispatching the settle.
- **Closure and cancellation are ONE operation, not two functions callers pair up.**
  Doug's architectural call (PrestaShop #176 `4156ad3`), after both ways of getting the
  pair wrong had shipped: *"the fix also requires that we make closure and enrolment
  cancelation a single atomic operation, not two separate functions as now. It's just
  begging to fail in some way."* It was. "Enter manually" closed without cancelling;
  every dropdown open cancelled without closing. Neither is a bug you find by reading the
  handler that has it — each one reads as a deliberate narrow choice, and the earlier
  version of this very bullet blessed it as one.

  So expose `abandonEnrollment()` — close, then cancel — and let every "the buyer is
  leaving this flow" gesture call that. Keep the halves callable only for a caller that
  genuinely wants one, and make it say why in a comment: on PrestaShop exactly two do
  (see the remaining gap below, and the panel's focus-out close, which takes the popup
  down without deciding anything about the enrolment because looking away is not a
  decision). The win is not tidiness, it is that a THIRD caller cannot be added with the
  ordering wrong or a half forgotten.
- **Prefer one atomic operation over a skip-this-half parameter.** The first draft of the
  re-render fix was a flag telling the reopen path not to cancel — which leaves the pair
  separable and the next caller free to get it wrong again. A parameter is still right for
  distinguishing *who is asking* (see the re-render rule below); it is wrong for
  splitting an operation that should not be splittable.

**Every gap this section used to log as "documented rather than fixed" is now FIXED
(PrestaShop #176 `4156ad3`, `ffe4b53`).** Kept here because the *shape* of each is what a
port needs to avoid, and because the fix is the atomic-operation rule above rather than
anything local to either symptom.

- **FIXED — a live popup went untracked whenever the panel reopened.** Reopening called
  the cancel path unconditionally, which nulls the popup handle — so the window was left
  on screen with nothing holding it, and the next Sole trader click opened a second one.
  Escape reaches this by hand (it goes straight to the panel close and never touches the
  popup), but the *common* trigger was not a buyer gesture at all: the platform's own
  address-form re-render restores the panel through the same reopen path, and per §17 that
  event can land tens of milliseconds after the click that opened the panel — its XHR
  callback is not blocked by the buyer being away in the popup window, so this fired at
  buyers who were *looking at* the thing it cancelled. Now a buyer-initiated reopen closes
  the popup as well as cancelling (so nothing is orphaned), and the re-render restore does
  neither. The billing-country change listener reached this too, and now closes as well —
  its tokens were minted against the country the buyer just left, so there is nothing to
  finish in that window.
- **FIXED — "Enter manually" closed the popup without cancelling the enrolment**, so a
  buyer lookup already in flight still resolved afterwards, and its write-back has no
  manual-entry guard: it overwrote the company name the buyer was now typing by hand and
  rendered the adopted-sole-trader affordance inside manual-entry mode. The credit check
  then ran on the identity they had just walked away from — §5's write-back state machine,
  reached through a gesture rather than a race. Escape has the same hole for the same
  reason (the panel close never cancels either). The chip now abandons; note the earlier
  round's reasoning that the reopen's own cancel covered it was wrong, because that cancel
  happens *before* the buyer can start a new flight from the reopened panel.
- **A silent auto-restore must not inherit a buyer gesture's side effects.**
  `restorePanelAfterRerender()` reopens a panel the platform tore down, and its doc already
  said it "restores only what the buyer already had" — but it reached that by calling the
  same `openDropdown()` a buyer click does, so it silently inherited the abandon. Pass the
  distinction explicitly (`openDropdown(buyerInitiated)`), and do NOT try to infer it from
  the reopen deadline being armed: the buyer's own click arms that too, so a re-render
  landing in the same tick as a genuine click would be indistinguishable. An argument at
  the call site cannot be ambiguous however the timing falls.
- **FIXED — instance teardown discarded the handle** (PrestaShop #176 `ffe4b53`). The
  platform destroys and rebuilds the search instance on every address-form re-render (§17),
  and `destroy()` cancels the enrolment to disown flights that would otherwise resolve
  against a replaced instance — but that cancel also nulled the popup handle, so the full
  re-render path left a live popup owned by nobody. Closing it there would be wrong: the
  enrolment object is a singleton that outlives the search instance, and the buyer may be
  filling the popup in because their shipping total recalculated behind it. So the cancel
  now takes a flag that disowns the WRITE only (`cancelEnrollment(keepPopupTracked)`) and
  leaves the poll and the handle alone; the settle event's popup-open guard needs no change
  and instead becomes the mechanism, holding the spinner until the buyer's own popup closes.
- **A surviving popup has to be RE-ADOPTABLE, not just re-findable** (round 2 adversarial
  review of the fix above). Disowning the write bumps the generation the popup's own
  completion message is checked against, so keeping the handle alive is only half an
  answer: the buyer finishes signing up, the message is dropped on that check, and they get
  an empty company field, no error, and — once the raise arms a spinner — something on
  screen actively claiming progress. Raising a tracked popup is therefore the same explicit
  resume as starting one, and re-stamps the token generation. Miss this and the two entry
  points silently disagree, because the replacement-flow link re-stamps on its own path and
  the chip does not.
- **The raise branch is load-bearing on all of this, and is a trap when changing it.** That
  branch sits before the re-entrancy guard. It used to be reachable only with a flight of
  the current panel-open session still running — and the fix above deliberately widened
  that: a popup now outlives the instance that launched it, so a *replacement* instance
  meets one it never started, with no flight of its own. Anything in that shape must decide
  what it owes the spinner/settle bookkeeping, because a raise with no spinner and no settle
  listener is not an answer — it leaves the restored panel with nothing to close it when the
  popup finally goes. The branch therefore arms the spinner itself, which is a no-op on its
  own re-entrancy guard in the ordinary same-session case.

**Cross-platform: WooCommerce had one of these two gaps and its architecture rules out the
other (WooCommerce PR #487 `7a11acb`).**

- **The re-render trigger cannot reach the flow.** WooCommerce's equivalent reopen path is
  `refresh()` → `hide()`, bound to `updated_checkout` — a coupon apply, a shipping-method
  change, a quantity edit, not only a country change. Its mode revert is gated on nothing
  being outstanding, and that predicate is true for as long as any popup RECORD exists, so
  an incidental re-render cannot null popup state under a live popup. The widget-rebuild
  paths are DOM-only and never touch popup or flight state, because the records live in a
  module-level array rather than being torn down with the widget. The porting lesson:
  records kept OFF the widget, plus every *derived* mode revert gated on "is anything still
  outstanding", is what makes the platform's own re-render harmless — a single handle
  nulled by a UI-restore function is what makes it fatal.
- **The gesture trigger did reach it.** Every exit from sole-trader mode funnelled through
  one function that dropped the popup records without CLOSING the windows and left the
  autofill lookup running. So a deliberate exit — click-to-reopen on a captured field, the
  Registered company chip, an ordinary registry pick — orphaned a still-open popup (the
  next chip click then opened a second over it) AND let the lookup re-enter sole-trader
  mode and re-adopt behind the buyer, credit check included. Fixed as one operation at that
  single choke point: close the windows, THEN drop the records, then invalidate the lookup
  through the same supersession counter the newer-flight case already used. Having exactly
  one choke point is why this was a small fix and not a redesign — the records-not-a-handle
  argument applies to the EXITS, not only the launches.
- **One divergence by design, not a gap.** PrestaShop counts "the write-back has no
  manual-entry guard" as a bug (fixed above). WooCommerce counts it as supported and pins
  it (#486): a buyer who says "my company isn't in the registry", starts typing, then
  corrects their email to one Two knows IS adopted, with the picker re-attached to render
  the adopted name. The two platforms disagree here by design, not by oversight — settle it
  the same way on both, or record why not, before porting a third platform from either one
  alone.

## 15. Delegated-auth tokens expire while checkout sits open

The tokens minted for autofill and the signup popup are short-lived. A buyer who parks
on checkout past their expiry loses autofill AND the signup flow — including the
post-adoption "select a different sole trader" path, which is the one most likely to be
used late in a long session. Refresh on a 30-minute interval, armed from the first real
mint (PrestaShop #172 `458f6bd`, WooCommerce #481 `cb63043`).

Everything below was found by adversarial review, not by testing:

- **Skip a tick while a signup popup opened against the OLD tokens is still open** —
  and re-check that inside the response handler, not only at tick start: a popup opened
  while the mint was in flight got silently orphaned when it landed (`078b3aa`,
  `0cae713`).
- **Discard a response whose country no longer matches the buyer's current billing
  country.** A slow tick's response could land after a newer, correct-country mint and
  overwrite it with the wrong jurisdiction's delegated authority — the same race the
  availability lookup had already been fixed for (`b2e60e4`).
- **A mint resolving after teardown must not arm a fresh interval on a dead instance**,
  which leaves an interval nothing can ever clear (`0cae713`).
- **`pagehide` must check `event.persisted`** and leave the interval running across a
  bfcache freeze/resume; tearing it down leaves a buyer restored mid-checkout with a
  dead refresh loop (`e159881`). Test it by dispatching a real `pagehide`, not by
  calling the stop function.
- **Rejected, deliberately:** holding the feature's in-flight/busy flag around the
  background mint. It does not close the race it looks like it closes (the email-change
  path never checks that flag before starting its own flight), and it would make an
  invisible background job gate real buyer interaction — the chip click, the reopen,
  the click-to-reopen (`e159881`).
- **Accepted tradeoff, documented rather than fixed:** a long-open popup blocks all
  refreshes, so expiry can still resurface in that narrow case.

## 16. Buyer-driven UI state must not live in a merchant setting

WooCommerce mutated `window.twoinc.enable_company_search` and
`manual_company_entry_active` at runtime — a merchant admin setting overloaded to also
carry which surface the buyer is currently using. Replaced by an explicit tri-state
capture mode, `'search' | 'manual' | 'sole_trader'` (`38bc49a`). Symptoms that
overloading caused, which are what to look for on another platform: the company-name
and company-summary readers reading the wrong surface for an adopted sole trader, and
the company-number label's visibility depending on the selected payment method.

- Keep that capture mode **distinct from the sole-trader flow's own mode**
  (`business | sole_trader`). One answers "which surface holds the company", the other
  "which flow is running"; conflating them is how the previous overload happened.
- **Snapshot the pre-sole-trader capture mode on the way in, restore it on the way
  out** — the buyer may genuinely have been mid manual entry.
- Anywhere you need to know whether company search is enabled at all *during* a
  sole-trader session, read the **saved merchant setting**, not the live flag, which
  the flow forces off for the session's duration (`004814f`).
- **Only a real mode TRANSITION may reset adoption / re-signup state.** A redundant
  same-mode call zeroed a live re-signup counter mid-flight: the prefetch calls
  "enter sole-trader mode" unconditionally whenever a flight resolves with a match,
  including while already in it — reachable by editing the email field (never locked,
  unlike the captured fields) while a "select a different" popup is open (`f8c035e`,
  round 6, after rounds 4 and 5 fixed the same bug via a different path).
- **If platform core can delete the company field outright, register a floor for it.**
  WooCommerce's block-checkout default sets the company field to `hidden`, and manual
  entry then had nothing to switch into on such a store (`38bc49a`).

## 17. Platform re-render will destroy your widget without telling you

The host checkout's own AJAX can replace the fragment containing the company-search
field without ever calling the widget library's `destroy`, so the library's
body-appended dropdown clone — and anything the port appended into it, chips included —
is orphaned in `<body>` forever. The freshly re-attached widget then opens a second
dropdown alongside the orphan, and clicks land on the wrong one (or are swallowed).

- **Sweep orphans for this field before creating a new widget** (`90ee75f`,
  TWO-25469) — and in the clear/reset path too (`30f3dab`): review found a second
  bypass site the first fix missed, which is the normal outcome here.
- **Sweep by CLASS, not by id.** A plain id selector returns exactly one match even
  when two elements share that id, so it cannot see the duplicate it exists to remove
  (`e4d00ab`).
- **Close the dropdown on the host's pre-swap event** (WooCommerce: `update_checkout`,
  which fires before the fragment replace) so there is nothing standing for the replace
  to orphan (`e4d00ab`).
- **Scope any stale-panel sweep to this field's own results id** (`50aaaa6`) — a
  broader sweep takes out a sibling field's live panel.
- **`close()` before `destroy()`**, every time.
- **Deferring a handler by a tick can strand the buyer** — see §7's manual-entry
  dead end. If you remove UI synchronously and defer the action, every refusal branch
  in that action lands after the UI is already gone.

## 18. Browser-side calls to Two must identify the client too

Every call the plugin's *browser* code makes straight to Two — company search, company
detail, order intent, sole-trader autofill — must carry the same `client` /
`client_v` pair the server-side calls have always attached (PrestaShop `cbcf933`).
Without it a shop's traffic arrives partly unattributed and version-adoption figures
count only the half that left the server. Details worth copying:

- **Query params, not body fields**, even on the POSTs — that is what the server-side
  helper's own POST/PUT branch does, and the order-intent body is a payload the server
  builds and Two validates, so adding to it would be a contract change rather than a
  metadata addition.
- Read the values from the published config and **never restate the version in
  JavaScript**, so a version bump stays a server-side-only change.
- **Only calls to Two's own host carry them** — the shop's own module/ajax URLs are
  deliberately left alone.
- Check the two sides agree: PrestaShop's browser config was built from the raw module
  version while the server helper appends `+<sha7>` on a deployed build, so one shop
  reported two different versions. Both must come from the same helper.
