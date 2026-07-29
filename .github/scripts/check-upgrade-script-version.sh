#!/usr/bin/env bash
#
# Reject a pull request whose NEW upgrade script is not named for the version
# this PR is computing (.github/scripts/decide-bump-level.sh).
#
# PrestaShop discovers upgrade scripts BY FILENAME: it runs
# `upgrade/upgrade-<version>.php` only for versions strictly above the one
# installed and at or below the one being installed, and derives the function
# name from the filename. Two consequences, both silent:
#
#   - appending a second migration to an already-installed version's script
#     means that migration NEVER RUNS on a shop that already reached that
#     version. Proven by experiment: `number_upgraded=0`, no error, no log line.
#   - a script named for a version the module never declares is never in range
#     for any upgrade, so it never runs at all.
#
# tests/UpgradeScriptVersionSpec.php already gates the static half of this
# (declared >= highest script, filename agrees with its function name). This
# check is the dynamic half that spec cannot see: it compares the ADDED
# filenames against the version this PR will actually land with. The two compose
# — do not duplicate either one in the other.
#
# Usage:  check-upgrade-script-version.sh <expected-version> [<base-ref>] [<head-ref>]
set -euo pipefail

expected="${1:?usage: check-upgrade-script-version.sh <expected-version> [<base-ref>] [<head-ref>]}"
base_ref="${2:-origin/staging}"
head_ref="${3:-HEAD}"

added=$(git diff --diff-filter=A --name-only "${base_ref}...${head_ref}" -- 'upgrade/upgrade-*.php' || true)

if [ -z "$added" ]; then
    echo "No new upgrade scripts in this PR — nothing to check."
    exit 0
fi

rc=0
while IFS= read -r path; do
    [ -n "$path" ] || continue
    v=${path##*/upgrade-}
    v=${v%.php}
    if [ "$v" = "$expected" ]; then
        echo "OK  ${path} matches the version this PR lands with (${expected})."
    else
        echo "::error file=${path}::this PR lands version ${expected}, so a new upgrade script must be named upgrade/upgrade-${expected}.php — ${path} declares ${v}. PrestaShop finds upgrade scripts by filename, so a mismatched name runs at the wrong time or never runs at all. Rename the file AND its upgrade_module_* function."
        rc=1
    fi
done <<<"$added"

exit "$rc"
