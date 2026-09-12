# AGENTS.md — Two Payment Module (PrestaShop)

Project-specific instructions for AI coding agents working in this repository.

## Scope

These rules apply to all files under this module directory.

## Mission

Build and maintain a robust B2B payment module where Two provider behavior and PrestaShop order state stay consistent, auditable, and safe.

## Hard Constraints

1. Never create a local PrestaShop order if Two rejects/fails order creation.
2. Apply rejection/rollback protections globally (not by country-specific exception).
3. Preserve provider-first flow and retry idempotency.
4. Do not weaken server-side validation in favor of frontend checks.
5. Keep tax/amount formulas consistent with existing test expectations. Relay the
   merchant's declared tax rate; never derive one from the amounts — see
   `.ai/vat-rate-sourcing.md`.
6. Never expose secrets in logs or code.
7. Never default to insecure transport behavior.

## Required Verification Before Claiming Done

Run from module root — these are the same gates CI runs:

```bash
make test      # php tests/run.php
make test-js   # jest over views/js (host Node 20+, not containerised)
make phpstan   # static analysis
```

If you touched shipping-tax resolution, also run the real-engine probes:
`make carrierless-shop && make test-integration` (undo with `make carrierless-off`).
CI runs them on PrestaShop 8 and 9.

Lint each PHP file you edited:

```bash
php -l path/to/file.php
```

`make help` lists the rest (local stack, version bump).

## i18n Requirements

For every user-facing string change:
- Update PHP/Smarty translation surfaces (`$this->l`, `{l ...}`)
- Update JS i18n dictionary in `twopayment.php` when used by frontend modules
- Update every locale in `translations/`: `es.php`, `nl.php`, `no.php`, `sv.php` — natural
  phrasing, not literal machine output. Dutch uses the informal `je`/`jouw` register.
- Avoid hardcoded English UI fallback where module i18n is available

**Never regenerate a `translations/*.php` file from the PrestaShop back office**
(Translations > Module translations). Its writer derives the key's source segment from the
*filename* for `.php` files as well as templates, but at runtime every `->l()` here reaches
`Module::l()` with no `$specific`, so the source segment is always the module name. Saving
from the back office therefore rewrites the module's own strings to keys nothing looks up.
Edit these files by hand. Norwegian is `no.php` (PrestaShop's `iso_code`), never `nb.php`.

## File Ownership Reference

- `twopayment.php`: hooks, settings, API interactions, payload and i18n map
- `controllers/front/payment.php`: checkout confirmation + order creation safety
- `controllers/front/orderintent.php`: order intent API and gating data
- `views/js/modules/*.js`: checkout UX logic and client validation
- `views/templates/hook/*.tpl`: admin and checkout rendering
- `tests/run.php`: tax/amount/order payload invariants (self-contained runner, no composer deps)

## Admin Settings Fail Loud: An Unrecognised Stored Value Is Never Priced

The standard for EVERY module setting, not only the surcharge method.

- Save refuses it. The form validator rejects a posted value outside the
  field's known set before anything is written, so a crafted POST cannot store
  a value nothing understands. Only the field's unset key persists as the
  default.
- Read paths raise. The settings getter is the single choke point: it maps the
  unset key to the default and throws for anything else. Callers that price a
  fee or build an order let that throw.
- Gates catch it. The payment-option hook withholds Two and nothing else; the
  hooks that render on every request take the contained read and degrade to
  "no fee" rather than 500ing the page. The getter logs the offending value
  once per request, so the catchers stay quiet.
- Buyer copy stays generic. The buyer sees the existing "not available for this
  order" wording. A setting name, a stored value or an enum key never reaches
  the storefront — those belong in `ps_log` and in the admin form's own error.
- The admin form keeps the lenient read, so a shop with a corrupt stored value
  still renders a correctable settings page.

Degrading a junk value to a working default is the failure this replaces: it
prices an order under a configuration nobody chose, and nobody is told.

## A Failed Buyer Fee Quote Withholds Two At Checkout Only

ABN-546. The same failure means different things on the two surfaces.

- **Checkout.** `isTwoSurchargeQuotableForCart()` is the ONE withholding gate, and
  `hookPaymentOptions()` returns `[]`, so the tile is absent from the list rather
  than offered-and-disabled. A fee quote that does not resolve withholds Two, because
  the alternative is an order created with no surcharge at all — a silent undercharge.
  The reason is logged at error level, once per render.
- **One term, never all of them**: the selected term, else the merchant default. The
  FX loop in the same predicate stays term-independent; a per-term condition inside it
  took whole stores offline once already (TWO-25276).
- **A quoted zero, an empty basket, a disabled surcharge and a term that prices no
  surcharge are answers, not failures**, and withhold nothing. Whether a term prices a
  surcharge is ONE predicate shared by the gate and the line builder — a cap with no
  percentage behind it prices nothing, and in fee-difference mode so does the default
  term unless a fixed amount rides along. If the two sides ever judge that separately,
  the gate offers Two and order create then refuses it over a fee of zero.
- **The order-create parity gate owns the term the buyer actually orders.** The gate
  runs at render, and the buyer can switch term inside the rendered tile; an
  unresolvable quote for the ordered term is a parity failure there and refuses the
  order, because zero on both sides otherwise reads as agreement.
- **Every surcharge quote shares one ceiling** (`API_TIMEOUT_SURCHARGE_PRICING`), not
  the render-path default, and the gate has no ceiling of its own. It favours
  availability over render latency: a shop whose pricing is merely slow keeps the tile
  rather than having it withheld over a fee the order would have priced correctly. The
  cost during an outage is one such timeout per payment-options render, and that is
  accepted.
- **The cross-request cookie cache holds the CHARGED term's successful quote only.**
  The whole cookie shares a 4KB browser cap, so a slot per offered term would let a
  chip-preview render push the shopper's session over it and lose their cart. Chip
  previews stay on the request-scoped cache, which does hold a null for the rest of
  that request.
- **Admin.** The configuration page previews merchant fee RATES from a different
  endpoint and degrades to a notice. `hookPaymentOptions()` never runs in admin, so a
  pricing outage can neither lock the merchant out nor blank that preview.
- The per-term checkout chips stay fail-soft: they show a number, they never decide
  one, so a failed quote zeroes that chip only.

## An Admin Save Stays Possible; The Buyer Path Fails Closed

**No verdict from the save-time API-key check blocks the General save** (ABN-495).
A connection failure, a timeout and a 5xx judge nothing about the key, and the form
re-renders from POST, so a blocked save looks stored while nothing was written and
leaves the merchant unable to store the replacement key that would fix the outage,
or even change the vendor name while it lasts. The verdict is reported as a warning
beside the save confirmation instead.

- **A key Two rejected (401/403) is the one submitted value a save discards** — the
  stored key is kept and the warning says so.
- The merchant short name is derived from the verification response, never
  submitted, so a save that resolved no merchant keeps the stored one.
- Storing a different key or environment refreshes the cached merchant record and
  never clears it (ABN-519). A merchant with two shops who cycles their key and
  updates only one must not have the other forget the terms, fees and minimum its
  admin controls are built from, and a typo saved during an outage must be
  recoverable by pasting the right key back. A record fetched for another key is
  withheld (ABN-530), not dropped.
- **A 200 carrying no merchant `id` is not a verified key** — a proxy, a captive
  portal or a maintenance page answers 200 too. It categorises as `error`, which
  rejects no key, so it withholds nothing (ABN-533). A record with an `id` but no
  short name IS verified; the empty short name withholds Two through its own gate.

## The Merchant Record Never Expires, And Only A Rejected Key Hides The Tile

ABN-519. The cached record — offerable terms, default term, buyer surcharge, platform
minimum, buyer countries, invoice-upload flag — has no expiry and is never evicted, on
any failure path. Only a successful refresh replaces it, and only from an event: the
API-key/environment save, `controllers/front/cron.php`, the Diagnostics button, or a
read standing in for a schedule that has stopped.

- **Nothing but the API-key verdict may withhold Two from the buyer, and only its
  DEFINITIVE rejections do** — `isDefinitiveFailureStatus()`: `invalid_key` and
  `not_configured` (ABN-533). An unresolvable record, a 500, an empty offer set, an
  unreachable or erroring key check: none of them hide the tile. That predicate is
  the one definition of the set; the payment POST's gate and the company-search
  affordance ask through it too, so nothing re-lists the categories.
- **An unresolved offer set offers the buyer NO term, never a substitute.**
  `getAvailablePaymentTerms()` reports what the record reports, or nothing;
  `getConfigurableTermSet()` is the admin-side question and is the only thing the
  hardcoded `PAYMENT_TERMS_OPTIONS` preset feeds. Do not compose a term for a buyer
  from that preset or from `DEFAULT_PAYMENT_TERM_DAYS` — a merchant may not hold it.
  With no offered term the tile renders with no term block at all, no fee line
  label is composed, and `controllers/front/payment.php` refuses the submission
  rather than book an order against a term nobody granted.
  `getDefaultPaymentTerm()` is null in that state and the browser seam publishes
  0, so neither PHP nor the checkout JS can name a day count (ABN-544).
- **A record past `MERCHANT_RECORD_STALE_AFTER` (26h) says the schedule is not
  running.** A read then refreshes it itself, at most once an hour and on a 2-second
  cap, and serves the record it holds either way. Staleness withholds nothing.
- **No admin control is gated on any of this**, and no failure blocks a save.
- A shop where no fetch has ever succeeded has nothing to serve, so a read retries on
  the short backoff until one does.

## State Propagation Runs Plugin To Provider Only

There is no inbound path from the provider or the merchant portal into this module, by design. A
portal-side action such as a refund leaves the shop's order status untouched, so that status is not
a reliable indicator of provider-side state. Do not add a one-off bridge for a single status; see
`.ai/decisions.md` (TWO-25706).

## Company Search: This Module's Own Implementation

`views/js/modules/TwoCompanySearch.js` is this module's own panel. The Magento and
WooCommerce plugins each carry a copy of one framework-free module, and nothing of
that is vendored here, so a fix to shared panel behaviour on those two platforms is
not a fix here, and vice versa. Never describe a change as cross-platform without
having made it in each module that carries the behaviour.

**The unsupported-country gate withdraws the SEARCH and nothing else.** It hides
the Registered Company chip and the query row; the panel still opens and manual
entry and the sole-trader route stay offered, because they are the only way a
buyer in an uncovered country has to name their company at all (ABN-525). The
country it reads is the wider company-search coverage, one global list — not the
Sole Trader chip's own per-country registry lookup, which is a different and
smaller list.

**The chip row is rendered whenever it offers a mode the buyer is not already
in**, not merely whenever it holds two chips. A lone chip for the current mode is
no choice; a lone chip for a different mode is the buyer's whole way out, which is
exactly the state an uncovered country with no sole-trader route leaves.

**`syncQueryFieldSuppression()` is the single authority on SHOWING the query
row.** The country gate only ever hides it and delegates back for the restore, so
the sole-trader condition is stated in one place and that method stays a pure
function of the selected chip.

**Focus arriving on the company-name field opens the panel**, with the caret in the
query field — or on the first chip on screen where the query row is not rendered,
so no state opens the panel with focus outside it, where neither the
Escape-to-close nor the close-on-focus-leave handler can see a keystroke.
`setupCompanyFieldOpeners()` binds focus, mousedown and keydown to one
`openDropdown()`.

**Closing the panel puts focus back on the company-name field** — Escape, a
pointer press outside it, a company adopted from the results, manual entry taking
the field over, and a sole-trader signup that answers or is abandoned (ABN-554).
The field's own focus opener is held off for that one programmatic focus alone, so
any keydown on the field, a pointer press on it, or focus arriving from anywhere
else brings the popover straight back.

**The close-on-focus-leave path is the exception, and deliberately so.** It only
fires once focus has settled on another control, so taking focus back would undo
the buyer's own Tab (TWO-25326). The same holds for the closes nothing in the
buyer's hands reached: a re-render, a country change mid-select, and another
popover claiming the single open slot.

**A press on the panel's own dead space is a no-op.** Its default action would
blur the caret out of the query field, and the panel's `mouseup` reclaim — which
exists for a scrollbar drag, where the browser drops focus with no cancellable
default — would then place focus the buyer never moved. So the press is
cancelled, except on a control, which a press is entitled to focus, and except
on a scrollbar, where cancelling would stop the drag scrolling the results
(ABN-554).

**A pointer press outside the popover takes focus back only where the press left
it nowhere**, and one tick later rather than in the handler: the press's own
default action runs after the handler and either focuses what it hit or clears
focus entirely, so focusing the field from the handler is simply undone. Neither
default action exists in jsdom, which is why this needed a real browser.

**The open panel takes the field's tab stop** — `tabindex="-1"` while it is up, and
on close the field's PRIOR value restored exactly, which is removal when there was
none (TWO-25503). Without it the focus opener is a keyboard trap: the opener puts
the caret in the query field, Shift+Tab returns to the field, and the opener pushes
focus forward again, so the buyer cannot get back past the control (WCAG 2.1.2).

**Only one popover is open, page-wide.** Opening one closes whichever other one
was open, enforced at open time rather than inferred from focus leaving the
first: a real pointer press on a second control need not deliver a focus event to
what it hits (ABN-510). The popover that closes gives its own field's tab stop
back before the newly opened one takes its. A pointer press outside the open
popover closes it too, with the company field counted as inside the control.
Core renders one editable address form per step, so a shop cannot currently put
two of these controls on a page; the guards are held identical to the other
platforms all the same, because divergence here is what makes the next
alignment change expensive.

The field is `readonly` in search mode, never `disabled`: a readonly input still
submits its value, still takes focus and is still a tab stop, and it IS
PrestaShop's own address field.

## What Focus Landing on the Checkout Does to an Open Signup Popup

Every focus on the checkout is classified once, whether a popup is up or not
(TWO-25658):

- **The chip that opened the popup on screen leaves it exactly as it is.** Only an
  activation of that chip moves it, and that chip's own click handler owns it. The
  exemption is per capture, held as the launching chip itself; a re-render's
  rebuilt chip inherits it by capture, a sibling capture's chip never does.
- **Any other control closes an open popup**, and the popover closes itself when
  focus lands outside it.
- **A different capture's Sole trader chip gets a popup of its own**, raised
  through that chip's own click handler so a launch is spelled out in one place.

Only focus this module moves is quiet. Focus moved by the theme, another module or
the browser — a validation jump, a restored scroll position, a password-manager
fill — reads as the buyer and takes the popup down. Close only, so the enrolment
survives and the chip reopens it.

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
- **jsdom's `getElementById` answers with the first-REGISTERED node, not the
  tree-first one**, so a fixture carrying a duplicate id silently resolves to the
  wrong element.
- **A mutation proves NEW coverage only when re-run against the base ref.** One the
  existing suite already catches proves the suite is sensitive, not that the case
  added covers anything.

## The Payment-Term Chips Owe The Radio-Group Keyboard Contract

The chip row advertises itself as a radio group — a `radiogroup` container, `radio`
chips, an `aria-checked` state — so it owes the W3C pattern's keyboard behaviour
(ABN-554). Advertising the role without it is the defect: a screen-reader buyer is
told "radio group" and finds none of the interaction that implies.

- **One tab stop, on the checked chip.** A roving `tabindex` keeps exactly one chip
  tabbable. A selection matching no offered chip falls back to the first, so no state
  drops the group out of the tab order.
- **The arrow keys move the checked selection**, wrapping at both ends, with Home and
  End for the first and last term; focus and selection move together, and the handler
  returns without preventing the default for an arrow carrying alt, ctrl or meta,
  since swallowing those breaks the browser's own shortcuts.
- **One predicate sets the selected class, `aria-checked` and the tab stop**, so the
  visual and programmatic states cannot drift apart.
- **Selection follows focus, so the persist is coalesced on the keyboard path.** Each
  arrow key changes the term, which persists it and re-quotes the fee; without
  coalescing an arrow sweep is one round trip per keystroke. A click persists at once.
- **A chip's visible text states the term in full**, so a standard-term chip carries
  no `aria-label` of its own: one restating "30 days" would risk WCAG 2.5.3. Only an
  end-of-month chip is named, because `EOM+30` needs spelling out — see below.
- **A single offered term is a `disabled` chip**: `disabled` takes it out of the tab
  order whatever its `tabindex`, and the arrow handler ignores a group of fewer than
  two enabled chips. It is therefore not reachable or announceable by keyboard at all.

## A Chip States Its Term Type, Not Just A Day Count

An end-of-month term falls due that many days after the end of the month, so a chip
reading "30 days" on a shop configured that way states the wrong due date (ABN-554).
The visible text is `30 days` under standard terms and `EOM+30` under end of month.

Only the end-of-month chip carries a `title` and an `aria-label`, and both read
`EOM+30: pay 30 days after the end of the month`. The name opens with the visible
token because WCAG 2.5.3 requires the accessible name to contain the visible text, so
the phrase may not be reordered to put the explanation first. It is one translated
sentence with the day count substituted, not a concatenation of translated fragments:
word order differs by language.

That name states the surcharge too, because an `aria-label` replaces the whole
accessible name and the amount rendered inside the chip is then announced nowhere:
`EOM+30: pay 30 days after the end of the month, plus a 7.25 EUR surcharge`. It is a
second whole sentence rather than the first with a clause appended, and its
placeholders are numbered because the day count and the amount are different values.
The quote lands after the chips are built, so each chip keeps both sentences on itself
and the name is restated when the amounts arrive — a failed or absent quote puts it
back to the one claiming no amount, alongside the blank it leaves in the chip.

## The Custom Request-Header Table

Every rule the save enforces — a name in the RFC 7230 token set, reserved names
matched case-insensitively, printable-ASCII values, no empty value — is re-applied
where a header is READ, since a stored value can arrive from a hand-edited row or
an import that no form validated. A refusal names the rule, never who sets the
header: the reason must be true of every reserved name, not of the one example that
prompted the question.
**A value pattern is anchored `\z`, never `$`** — `$` also matches immediately
before a trailing newline, which is precisely the byte a printable-ASCII rule exists
to refuse, and a header value ending in one is a response-splitting sink.
**The header table gets no data patch or migration, deliberately.** The
single-value setting it replaces never reached a production release on any
platform, so no merchant ever had one configured; do not add one on the assumption
that stored values exist.

## A Guard Is Invoked Through `bash`

A script committed mode `100644` and run as `./script.sh` exits 126. On a CI
dashboard that is indistinguishable from a check that ran and failed, so the guard's
own absence reads as its verdict. Invoke anything whose failure mode is "did not
execute" as `bash script.sh`, and have it print what it checked.

## This Is A Public Repository

- No partner or merchant name reaches file contents, a commit body, a branch name or
  a PR title or body. Gate before pushing: a force-push afterwards does not remove a
  commit from GitHub's history.
- In comments, commit messages and PR bodies alike, cite a Linear ticket id and
  nothing else: a section, question or ruling number belonging to an internal review
  document means nothing to a reader outside the company, and neither does a person
  named as the authority for a rule.
- Describe another plugin's behaviour in your own words; never reproduce its source
  text, schema fragments or test identifiers here.
- Name the repository on every pull-request or issue reference:
  `prestashop-plugin PR #211`, `woocommerce-plugin PR #487`. A bare `#211` renders
  as a live link to whatever that number happens to be in this repository. A Linear
  id such as `TWO-25326` is unambiguous on its own and needs no prefix.

## Change Quality Rules

- Keep diffs targeted; avoid unrelated refactors in payment-critical paths.
- Preserve backward compatibility unless change request is explicit.
- Add or update tests for behavior changes in payload, validation, or flow control.
- Update `CHANGELOG.md` for functional changes.

## Release Consistency Rules

**Do not hand-bump the version for a PR into `staging`.** The bump is automated
(`.github/workflows/version-bump.yml`, TWO-25256): the version is computed from
this PR's own conventional-commit subjects and committed onto the PR's branch, so
by review time the tree already declares the version it will ship as. `make bump`
previews that decision and writes nothing. `main` computes nothing at all - it
tags the version already in the tree.

When bumping/releasing versions, keep these in sync:
- `twopayment.php` version
- `config.xml` version
- `CHANGELOG.md`

**Upgrade scripts.** PrestaShop executes `upgrade/upgrade-<version>.php` only
for versions **strictly above** the installed one, and derives the function name
from the filename. Both halves fail *silently* — no error, no log line, just a
merchant whose data was never migrated. So:
- `upgrade/upgrade-X.Y.Z.php` must declare `upgrade_module_X_Y_Z()`;
- the declared module version must be **at least** the highest `upgrade/` filename
  (equal is the normal case — a script is named for the version it upgrades *to*;
  only a script numbered *above* the declared version is unreachable).

**A new upgrade script must be named for the version the PR lands with.** That is
why the version computation has a PrestaShop-only clause: a PR that adds a new
`upgrade/upgrade-<version>.php` forces a patch bump even when nothing else in the
PR earns one, so the script gets a filename of its own.
`.github/scripts/check-upgrade-script-version.sh` rejects the PR if an added
script's filename does not match the computed version. This exists because
appending a migration to an already-installed version's script was verified by
experiment to never run at all on a shop that already reached that version
(`number_upgraded=0`, silent). It composes with the static gate below - do not
duplicate either in the other.

`tests/UpgradeScriptVersionSpec.php` gates both. The version sequence is
legitimately **non-contiguous** (2.6.7 was deliberately skipped, and most
releases need no migration at all) — never add a contiguity check.

**Touching anything under `override/` is a MIGRATION, not an edit.** The module's
`override/` directory is a **template**. PrestaShop copies it into the *shop's*
own override tree once, at install or reset, and from then on the shop's copy is
the file that executes. Nothing rewrites that copy — not an upgrade, not a
deploy, not a git-sync, not a disable/enable. `Module::addOverride()` cannot even
do it when it runs: for every method the shop copy already declares it *throws*
rather than replacing, and it has no path that removes one. A module **reset**
is the one back-office action that does fix a stale copy, because it uninstalls
the override before reinstalling it — but it drops the module's data and hook
registrations, so it is a merchant's recovery step, never a release mechanism. So:

- **editing** an override changes nothing on any existing shop;
- **retiring** one leaves it running forever.

Both are **silent** — new version reported, new files on disk, green deploy, old
behaviour on the storefront. That combination cost a day of diagnosis in
TWO-25265, where a shop stamped `2.4.0` kept injecting retired address-form
fields while reporting `2.7.0`.

So the version that changes or retires an override must call
`TwoOverrideMigrator::refresh($module)` from its upgrade script, naming any
**retired** path explicitly (a retired file is gone from the module tree, so it
cannot be discovered). `.github/scripts/check-override-migration.sh` fails the PR
otherwise; `.github/scripts/test-check-override-migration.sh` tests the check.
Never delete a shop-level override that carries another module's `module:` stamp —
that tree is a shared merge target, and `classes/TwoOverrideMigrator.php`
deliberately refuses to touch co-owned or unstamped files.

Related but **not** the same problem: `.tpl` changes also go stale on a shop,
because a compiled Smarty template is never regenerated while
`PS_SMARTY_FORCE_COMPILE` is `0`. That is shop configuration, not a migration, and
is fixed in the deployment chart — nothing in this repo can address it.

## Common Failure Patterns to Avoid

- Reintroducing local order writes before provider success.
- Losing idempotency on retries/timeouts.
- Country-specific tax/error branching that bypasses global safeguards.
- Admin UI showing invoice actions too early in order lifecycle.
- Updating JS messages without adding corresponding translation keys.
