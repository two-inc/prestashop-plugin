#!/usr/bin/env bash
# dev/ci/lint-module.sh: php -l every PHP file that runs on a merchant's shop
# or inside a CI shop container, on whatever PHP is on the PATH (TWO-27173).
#
# The lint job runs this on every PHP minor from the module's declared floor
# (README.md, "**PHP**: 7.2+") to the newest php.net supports, so a construct
# newer than the floor fails here rather than on a merchant's shop.
#
# In scope: every tracked .php file except the offline suite under tests/
# (PHP 8.1 syntax, linted and run by the offline suite job) and the host-side
# developer tools directly under dev/. The integration probes and dev/ci/
# helpers are in scope: they run inside the CI shop, PHP 7.2 included.
set -euo pipefail
cd "$(dirname "$0")/../.."

php -v | head -1
fail=0 n=0
while IFS= read -r -d '' f; do
    n=$((n + 1))
    if ! out=$(php -l "$f" 2>&1); then
        echo "::error file=$f::$out"
        fail=1
    fi
done < <(git ls-files -z -- '*.php' | grep -zvE '^(tests/|dev/[^/]+\.php$)'; git ls-files -z -- 'tests/integration/*.php')
[ "$n" -gt 0 ] || { echo "::error::lint-module: no PHP files found"; exit 1; }
echo "linted $n files"
exit "$fail"
