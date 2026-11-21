#!/usr/bin/env bash
#
# Unit tests for version-lt.sh.
#
# The comparison decides whether smoke.yml's upgrade leg exercises a
# version-gated upgrade script or skips it, so a wrong answer is a silently
# green build rather than a red one. Same style as
# test-decide-bump-level.sh / test-check-override-migration.sh: a plain shell
# self-test, run as its own CI job by the workflow that uses the script.
set -uo pipefail

SCRIPT="$(cd "$(dirname "$0")" && pwd)/version-lt.sh"

pass=0
fail=0

# check <expected: lt|ge> <a> <b>
check() {
    local expected=$1 a=$2 b=$3 actual
    if "$SCRIPT" "$a" "$b"; then
        actual=lt
    else
        actual=ge
    fi

    if [ "$actual" = "$expected" ]; then
        pass=$((pass + 1))
        printf ' ok    %-8s %-8s -> %s\n' "$a" "$b" "$actual"
    else
        fail=$((fail + 1))
        printf ' FAIL  %-8s %-8s -> %s (expected %s)\n' "$a" "$b" "$actual" "$expected"
    fi
}

echo "--- the 2.7.6 migration gate in smoke.yml -----------------------------------"
# In range: the prior release is below 2.7.6, so upgrade_module_2_7_6() will run
# and the old config key must be seeded and asserted.
check lt 2.5.1 2.7.6
check lt 2.7.5 2.7.6
# Out of range: 2.7.6 or later installs the new key itself and the upgrade script
# never runs, so seeding the old key would leave a row nothing removes.
check ge 2.7.6 2.7.6
check ge 2.7.7 2.7.6
# The double-digit case: lexically "10" < "6", so a string compare calls this
# in-range and the seed leaves a permanent red build behind.
check ge 2.7.10 2.7.6
check ge 2.8.0 2.7.6
check ge 2.10.0 2.7.6
check ge 3.0.0 2.7.6

echo
echo "--- comparisons a string compare gets wrong ---------------------------------"
check lt 2.7.6 2.7.10
check lt 2.9.0 2.10.0
check lt 2.10.0 3.0.0
check ge 3.0.0 2.10.0
check ge 2.10.0 2.9.0

echo
echo "--- degenerate input --------------------------------------------------------"
check ge 2.7.6 2.7.6

if "$SCRIPT" 2.7.6 >/dev/null 2>&1; then
    fail=$((fail + 1))
    echo " FAIL  one argument -> exit 0 (expected non-zero)"
else
    pass=$((pass + 1))
    echo " ok    one argument -> non-zero"
fi

echo
echo "=============================================================================="
printf ' %d passed, %d failed\n' "$pass" "$fail"
echo "=============================================================================="
[ "$fail" -eq 0 ]
