#!/usr/bin/env bash
#
# Version comparison: exit 0 when $1 is strictly LOWER than $2, 1 otherwise.
#
#   .github/scripts/version-lt.sh 2.7.5 2.7.6   # exit 0
#   .github/scripts/version-lt.sh 2.7.6 2.7.6   # exit 1
#
# WHY THIS IS A SCRIPT AND NOT A SHELL FUNCTION IN A WORKFLOW
#
# It gates whether a CI step exercises a version-gated upgrade script at all
# (`.github/workflows/smoke.yml`), so getting it wrong does not go red - it goes
# GREEN having tested nothing. Inverting the comparison in the inline function it
# replaced left the whole suite passing. As a script it has a self-test next to
# it (`test-version-lt.sh`, run by the same workflow), which is the only reason
# the comparison is checked at all.
#
# The cases a naive `[ "$1" \< "$2" ]` string compare gets wrong, and which the
# self-test pins: double-digit components (2.7.10 is ABOVE 2.7.6, not below it,
# because 1 < 6 lexically) and cross-major comparisons (2.10.0 vs 3.0.0).
set -uo pipefail

if [ "$#" -ne 2 ]; then
    echo "usage: $(basename "$0") <version> <version>" >&2
    exit 2
fi

# `sort -V` is version sort, not string sort, and is what makes the double-digit
# cases come out right. Equal versions are excluded first, because sort -V puts
# an equal pair's first line at the head regardless of which argument it was.
[ "$1" != "$2" ] \
    && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]
