#!/usr/bin/env bash
#
# Reject a pull request that changes or retires a file under `override/` without
# migrating the shops that already have the old copy.
#
# THE DEFECT THIS EXISTS TO PREVENT
#
# A module's `override/` directory is a TEMPLATE, not deployed content.
# PrestaShop copies it into the SHOP's own override tree once, at install or
# reset, and from then on the shop's copy is the file that executes. Nothing
# rewrites that copy - not an upgrade, not a deploy, not a git-sync, not a module
# reset. `Module::addOverride()` cannot rewrite it even when it runs: for every
# method the shop copy already declares it throws rather than replacing, and it
# has no path that removes one.
#
# So editing a file under `override/` changes NOTHING on any existing shop, and
# deleting one leaves it running FOREVER. Both are silent. No error, no warning,
# no log line, no failing test - the module reports the new version, the files on
# disk are the new version, the deploy is green, and the storefront runs the old
# behaviour. That exact combination cost a day of diagnosis on 2026-07-29
# (TWO-25265): a shop stamped 2.4.0 kept injecting retired address-form fields
# while reporting 2.7.0.
#
# THE RULE
#
# Changing or retiring an override is a MIGRATION. The version that does it must
# carry an `upgrade/upgrade-<version>.php` that calls `TwoOverrideMigrator` and
# names the affected class. This check fails the PR when it does not.
#
# Consequence, and it is intended rather than a side effect: any override edit
# now forces a new upgrade script, which
# `.github/scripts/decide-bump-level.sh` in turn forces to a version of its own.
# An override edit is a migration-bearing change and is priced like one. If a
# change genuinely needs no migration - a comment, a docblock, reformatting -
# say so in the upgrade script and let it be a no-op, or do not touch the file.
#
# WHAT THIS CHECK CANNOT SEE. Read this before trusting it.
#
#   1. SHOP STATE. It reads the repository, never a shop. A shop already carrying
#      a stale override from before this check existed stays stale until some
#      version's migration reaches it. This gate stops new instances; it does not
#      discover old ones.
#   2. WHETHER THE MIGRATION WORKS. It greps the added upgrade-script lines for
#      the class name. A comment mentioning the class satisfies it. It cannot
#      tell a real `TwoOverrideMigrator::refresh()` call from the word
#      `CustomerAddressFormatter` in a docblock.
#   3. DRIFT WITH NO PULL REQUEST. A shop installed at an old version and never
#      upgraded has no PR to gate. Nothing here applies.
#   4. A DIRECT PUSH. It runs on `pull_request`. A push straight to a protected
#      branch bypasses it, as it bypasses every other PR gate.
#   5. OTHER OWNERS. PrestaShop's `override/` tree is shared - core's own
#      overrides and other modules' spliced methods live there too. This check
#      only sees this repository's `override/` directory and says nothing about
#      the rest of the tree.
#   6. THE FILENAME/VERSION CONTRACT. That is a separate gate
#      (`check-upgrade-script-version.sh` plus `tests/UpgradeScriptVersionSpec.php`).
#      Do not duplicate it here.
#   7. SMARTY TEMPLATES. `.tpl` changes have their own staleness problem
#      (compiled templates are not regenerated when `PS_SMARTY_FORCE_COMPILE` is
#      0) but that is a shop-configuration fix, not a migration, and is handled
#      chart-side in `two-inc/platform-tools`. Out of scope here.
#
# Usage:  check-override-migration.sh [<base-ref>] [<head-ref>]
#         defaults: origin/staging HEAD
set -euo pipefail

base_ref="${1:-origin/staging}"
head_ref="${2:-HEAD}"

# THREE dots. A two-dot diff on a branch that is behind its base reports the
# base's own commits as reverse deltas, which here would read as a pile of
# phantom override deletions.
range="${base_ref}...${head_ref}"

# Pathspec is the plain directory, filtered below. A `**` pathspec depends on
# git's glob magic being enabled and silently matches nothing when it is not,
# which would make this gate pass by accident — the one failure mode a gate must
# not have.
#
# `--no-renames` deliberately: a retired-and-replaced override can otherwise be
# reported as a rename, and a rename of an override file is exactly as
# migration-bearing as a delete-plus-add. Forcing D+A makes both visible.
#
# `index.php` is PrestaShop's directory-listing stub, present in every directory
# of every module. It is not an override and carries no behaviour.
list_touched() {
    git diff --no-renames --diff-filter="$1" --name-only "$range" -- override/ 2>/dev/null |
        grep -E '\.php$' | grep -vE '(^|/)index\.php$' || true
}

deleted=$(list_touched D)
modified=$(list_touched M)

if [ -z "$deleted" ] && [ -z "$modified" ]; then
    echo "No override files changed or removed in this PR — nothing to check."
    exit 0
fi

# Added lines of any upgrade script this PR adds or edits. Added lines only: an
# upgrade script that merely already mentions the class, unchanged, is not a
# migration for THIS change.
upgrade_additions=$(git diff --no-renames "$range" -- 'upgrade/upgrade-*.php' |
    grep '^+' | grep -v '^+++' || true)

explain() {
    echo ""
    echo "A module's override/ directory is a TEMPLATE. PrestaShop copies it into the"
    echo "shop's own override tree once, at install, and never rewrites that copy —"
    echo "not on upgrade, not on deploy, not on a module reset. So an override edit"
    echo "reaches NO existing shop, silently, and a retired override keeps running"
    echo "forever. See classes/TwoOverrideMigrator.php and TWO-25265."
}

rc=0

# A MODIFIED override is auto-discovered by TwoOverrideMigrator (it walks the
# module's own override tree), so the requirement is only that the version calls
# the migrator at all.
if [ -n "$modified" ]; then
    if printf '%s' "$upgrade_additions" | grep -qF 'TwoOverrideMigrator'; then
        while IFS= read -r path; do
            [ -n "$path" ] && echo "OK  ${path} modified — an upgrade script in this PR calls TwoOverrideMigrator."
        done <<<"$modified"
    else
        while IFS= read -r path; do
            [ -n "$path" ] || continue
            echo "::error file=${path}::${path} was modified, but no upgrade script in this PR calls TwoOverrideMigrator::refresh(). Every shop already installed keeps running its old copy of this override, silently."
        done <<<"$modified"
        explain
        echo ""
        echo "FIX: add upgrade/upgrade-<this PR's version>.php calling"
        echo "     TwoOverrideMigrator::refresh(\$module);"
        rc=1
    fi
fi

# A DELETED override cannot be discovered — it is gone from the module tree — so
# the upgrade script has to name its path explicitly in the retired-paths
# argument. Requiring the class name in the added lines is the closest a static
# check can get to verifying that.
if [ -n "$deleted" ]; then
    # Tracked separately from $rc: $rc may already be 1 from the MODIFIED branch
    # above, and printing the retired-paths hint for a failure that was actually
    # about a modified file sends the reader to the wrong fix.
    deleted_rc=0

    while IFS= read -r path; do
        [ -n "$path" ] || continue
        class=$(basename "$path" .php)

        # Word-boundary match, not a bare substring: `grep -qF Foo` is satisfied
        # by the string `FooBar` appearing anywhere in the added lines, which
        # would pass the gate for a retired `Foo.php` that nothing migrates.
        # PrestaShop class names are [A-Za-z0-9_]+, so the class name is safe to
        # interpolate into a pattern; anything else is a filename we do not
        # understand, and the safe answer there is to demand a human look.
        case "$class" in
            *[!A-Za-z0-9_]* | '')
                echo "::error file=${path}::${path} has a basename this check cannot match safely (${class}). Rename it, or migrate it by hand and say so."
                deleted_rc=1
                continue
                ;;
        esac

        if printf '%s' "$upgrade_additions" | grep -qE "(^|[^A-Za-z0-9_])${class}([^A-Za-z0-9_]|$)"; then
            echo "OK  ${path} retired — an upgrade script in this PR names ${class}."
            continue
        fi

        echo "::error file=${path}::${path} was RETIRED, but no upgrade script in this PR mentions ${class}. A retired override cannot be auto-discovered (it is gone from the module tree), so it must be named in TwoOverrideMigrator::refresh()'s retired-paths argument — otherwise every shop that has it keeps running it forever."
        deleted_rc=1
    done <<<"$deleted"

    # NOT `[ ... ] && rc=1`: under `set -e` a false test makes that list return 1
    # and kills the script, which here would mean exiting non-zero for the right
    # reason by accident and skipping the hint below.
    if [ "$deleted_rc" -ne 0 ]; then
        rc=1
    fi

    if [ "$deleted_rc" -ne 0 ]; then
        explain
        echo ""
        echo "FIX: add upgrade/upgrade-<this PR's version>.php calling"
        echo "     TwoOverrideMigrator::refresh(\$module, array('classes/form/Whatever.php'));"
    fi
fi

exit "$rc"
