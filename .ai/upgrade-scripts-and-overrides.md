---
description: "Upgrade-script naming and versioning, and why changing override/ is a migration"
---

# Upgrade Scripts And Overrides

Moved verbatim from `AGENTS.md`.

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
