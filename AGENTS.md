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

`make help` lists the rest (local stack, formatter, version bump).

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

## Company Search: This Module's Own Implementation

`views/js/modules/TwoCompanySearch.js` is this module's own panel. The Magento and
WooCommerce plugins share one framework-free panel module between them; nothing of
that is vendored here, so a fix to shared panel behaviour on those two platforms is
not a fix here, and vice versa. Never describe a change as cross-platform without
having made it in each module that carries the behaviour.

**The unsupported-country gate disables the Registered Company chip, never manual
entry.** Manual entry hands the field over as a plain typeable input that never
reaches the registry, so disabling it there blocks a mode that was never going to
search and leaves a buyer in an uncovered country with no way to name their company
at all.

**Focus alone does not open the panel here.** Only a real click on the company-name
field, or a keypress on it other than Tab, opens it — the requirement this module
implements, stated in `setupCompanyFieldOpeners()`. The Magento panel opens on
focus. That is a live divergence between the platforms, not an oversight: do not
claim parity, and do not harmonise either one to the other without a product ruling.

The field is `readonly` in search mode, never `disabled`: a readonly input still
submits its value, still takes focus and is still a tab stop, and it IS
PrestaShop's own address field.

## What Focus Landing on the Checkout Does to an Open Signup Popup

Every focus while the hosted sole-trader signup window is up is classified once
(TWO-25658):

- **The Sole trader chip leaves the popup exactly as it is.** Only an activation of
  that chip moves it, and that chip's own click handler owns it.
- **Any other control closes an open popup**, and the popover closes itself when
  focus lands outside it.

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

## The Custom Request-Header Table

Every rule the save enforces — reserved names matched case-insensitively,
printable-ASCII values, no empty name — is re-applied where a header is READ, since
a stored value can arrive from a hand-edited row or an import that no form
validated. A refusal names the rule, never who sets the header: the reason must be
true of every reserved name, not of the one example that prompted the question.
**A value pattern is anchored `\z`, never `$`** — `$` also matches immediately
before a trailing newline, which is precisely the byte a printable-ASCII rule exists
to refuse, and a header value ending in one is a response-splitting sink.
**There is deliberately no data patch** for the single token field it replaced —
that field never reached a production release on any platform, so no merchant ever
had one configured; do not add one on the assumption that stored values exist.

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
