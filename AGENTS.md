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
is fixed chart-side in `two-inc/platform-tools` — nothing in this repo can address
it.

## Common Failure Patterns to Avoid

- Reintroducing local order writes before provider success.
- Losing idempotency on retries/timeouts.
- Country-specific tax/error branching that bypasses global safeguards.
- Admin UI showing invoice actions too early in order lifecycle.
- Updating JS messages without adding corresponding translation keys.
