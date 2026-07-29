#!/usr/bin/env bash
#
# Self-test for check-override-migration.sh, against throwaway git repos. No
# network, no docker, a couple of seconds.
#
# It exists because the failure mode of a gate is not "it is red when it should
# be green" — that gets noticed within minutes. It is "it is green when it should
# be red", which gets noticed never. Half the cases below therefore assert a
# FAILURE, and one of them (the empty-pathspec case) exists purely because an
# earlier draft of the check used a `override/**/*.php` pathspec that silently
# matched nothing.
#
# Usage: test-check-override-migration.sh
set -euo pipefail

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
check="${script_dir}/check-override-migration.sh"

pass=0
fail=0

# Build a repo with a `base` branch holding $1 as the override tree, then a
# `head` branch applying the mutation in $2 (a shell snippet run in the repo).
# Echoes the repo path.
make_repo() {
    local setup="$1" mutation="$2"
    local dir
    dir=$(mktemp -d)

    (
        cd "$dir"
        git init -q -b base
        git config user.email t@t.t
        git config user.name t
        mkdir -p override/classes/form upgrade
        printf '<?php\n' >override/index.php
        printf '<?php\n' >upgrade/index.php
        eval "$setup"
        git add -A
        git commit -qm base

        git checkout -q -b head
        eval "$mutation"
        git add -A
        git commit -qm head
    ) >/dev/null 2>&1

    printf '%s' "$dir"
}

# $1 name, $2 expected exit (0 or 1), $3 setup, $4 mutation
expect() {
    local name="$1" want="$2" setup="$3" mutation="$4"
    local dir rc=0 out

    dir=$(make_repo "$setup" "$mutation")
    out=$(cd "$dir" && bash "$check" base head 2>&1) || rc=$?
    rm -rf "$dir"

    if [ "$rc" -eq "$want" ]; then
        echo "PASS  ${name}"
        pass=$((pass + 1))
    else
        echo "FAIL  ${name} — wanted exit ${want}, got ${rc}"
        printf '%s\n' "$out" | sed 's/^/        /'
        fail=$((fail + 1))
    fi
}

OVERRIDE="printf '<?php\nclass Foo extends FooCore { public function a() {} }\n' > override/classes/form/Foo.php"

# --- the green cases ---------------------------------------------------------

expect "untouched overrides need nothing" 0 \
    "$OVERRIDE" \
    "printf 'unrelated\n' > README.md"

expect "no override tree at all" 0 \
    "true" \
    "printf 'unrelated\n' > README.md"

expect "modified override + a migrator call passes" 0 \
    "$OVERRIDE" \
    "printf '<?php\nclass Foo extends FooCore { public function a() { return 1; } }\n' > override/classes/form/Foo.php
     printf '<?php\nfunction upgrade_module_9_9_9(\$m) { TwoOverrideMigrator::refresh(\$m); }\n' > upgrade/upgrade-9.9.9.php"

expect "retired override named in the upgrade script passes" 0 \
    "$OVERRIDE" \
    "git rm -q override/classes/form/Foo.php
     printf '<?php\nfunction upgrade_module_9_9_9(\$m) { TwoOverrideMigrator::refresh(\$m, array(\"classes/form/Foo.php\")); }\n' > upgrade/upgrade-9.9.9.php"

expect "an ADDED override needs no migration" 0 \
    "true" \
    "printf '<?php\nclass Foo extends FooCore {}\n' > override/classes/form/Foo.php"

expect "override/index.php stubs are not overrides" 0 \
    "$OVERRIDE" \
    "printf '<?php\n// touched\n' > override/index.php
     printf '<?php\n// touched\n' > override/classes/form/index.php"

# --- the red cases, which are the ones that matter ---------------------------

expect "modified override with NO upgrade script fails" 1 \
    "$OVERRIDE" \
    "printf '<?php\nclass Foo extends FooCore { public function a() { return 1; } }\n' > override/classes/form/Foo.php"

expect "retired override with NO upgrade script fails" 1 \
    "$OVERRIDE" \
    "git rm -q override/classes/form/Foo.php"

expect "modified override + an upgrade script that ignores it fails" 1 \
    "$OVERRIDE" \
    "printf '<?php\nclass Foo extends FooCore { public function a() { return 1; } }\n' > override/classes/form/Foo.php
     printf '<?php\nfunction upgrade_module_9_9_9(\$m) { Configuration::updateValue(\"X\", 1); }\n' > upgrade/upgrade-9.9.9.php"

expect "retired override + a migrator call that does not name it fails" 1 \
    "$OVERRIDE" \
    "git rm -q override/classes/form/Foo.php
     printf '<?php\nfunction upgrade_module_9_9_9(\$m) { TwoOverrideMigrator::refresh(\$m); }\n' > upgrade/upgrade-9.9.9.php"

# A NESTED path, specifically. The check's pathspec must reach files below the
# first level of override/ — the real case is override/classes/form/, three deep.
# An `override/**/*.php` pathspec matches none of it unless git's glob magic
# happens to be on, and a pathspec that matches nothing makes this gate pass
# everything.
expect "a deeply nested override is still seen" 1 \
    "mkdir -p override/classes/checkout/step && printf '<?php\nclass Bar extends BarCore { public function a() {} }\n' > override/classes/checkout/step/Bar.php" \
    "printf '<?php\nclass Bar extends BarCore { public function a() { return 1; } }\n' > override/classes/checkout/step/Bar.php"

# A pre-existing upgrade script that already mentions the class, UNCHANGED, is
# not a migration for this change. Only added lines count.
expect "an unchanged upgrade script mentioning the class does not count" 1 \
    "$OVERRIDE
     printf '<?php\n// Foo was migrated long ago.\nfunction upgrade_module_1_0_0(\$m) { TwoOverrideMigrator::refresh(\$m); }\n' > upgrade/upgrade-1.0.0.php" \
    "printf '<?php\nclass Foo extends FooCore { public function a() { return 1; } }\n' > override/classes/form/Foo.php"

# Raised in adversarial review. `grep -qF Foo` is satisfied by `FooBar`, so a
# retired Foo.php would pass the gate on an upgrade script that only ever
# mentions an unrelated FooBar. Substring matching in a gate is a false PASS,
# which is the only direction that matters.
expect "a longer class name does not satisfy a retired shorter one" 1 \
    "$OVERRIDE" \
    "git rm -q override/classes/form/Foo.php
     printf '<?php\nfunction upgrade_module_9_9_9(\$m) { TwoOverrideMigrator::refresh(\$m, array(\"classes/form/FooBar.php\")); }\n' > upgrade/upgrade-9.9.9.php"

# ...and the boundary must not be so strict that the real quoted path fails.
expect "the class named inside a quoted path still matches" 0 \
    "$OVERRIDE" \
    "git rm -q override/classes/form/Foo.php
     printf '<?php\nfunction upgrade_module_9_9_9(\$m) { TwoOverrideMigrator::refresh(\$m, array(\"classes/form/Foo.php\")); }\n' > upgrade/upgrade-9.9.9.php"

# When only the MODIFIED branch fails, the retired-paths hint must not be
# printed — it points at the wrong fix. (The exit code for this scenario is
# already covered above; this asserts on the OUTPUT.)
dir=$(make_repo "$OVERRIDE" \
    "printf '<?php\nclass Foo extends FooCore { public function a() { return 1; } }\n' > override/classes/form/Foo.php
     printf '<?php\nfunction upgrade_module_9_9_9(\$m) { Configuration::updateValue(\"X\", 1); }\n' > upgrade/upgrade-9.9.9.php")
out=$(cd "$dir" && bash "$check" base head 2>&1) || true
rm -rf "$dir"
if printf '%s' "$out" | grep -q 'retired-paths\|array('; then
    echo "FAIL  modified-only failure printed the retired-paths hint"
    fail=$((fail + 1))
else
    echo "PASS  modified-only failure prints only the modified hint"
    pass=$((pass + 1))
fi

echo ""
echo "${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ]
