#!/usr/bin/env bash
# Render baseline for the "Tax codes for 0% lines" section (TWO-26153).
#
# Renders every case in tests/fixtures/tax-admin-cases.php on the base commit and on the working tree, and compares
# the Configuration rows each render wrote. Only the section's own admin slots may differ; any other difference is a
# write that reaches order time, and fails.
#
# Usage: dev/tax-admin-baseline.sh [base ref]   (default: the merge base with origin/staging)
#        PHP=php8.2 dev/tax-admin-baseline.sh   to pick the interpreter
set -euo pipefail

cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
BASE="${1:-$(git merge-base HEAD origin/staging)}"
ALLOWED='PS_TWO_TAX_CODE_PROFILE|PS_TWO_TAX_CODES|PS_TWO_TAX_CODES_TRIED_TS'

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/base"
# Not git archive: .gitattributes export-ignores tests/, which the render needs.
GIT_INDEX_FILE="$work/index" git --work-tree="$work/base" checkout "$BASE" -- .

"$PHP" -d display_errors=stderr dev/tax-admin-baseline.php "$work/base" > "$work/base.json"
"$PHP" -d display_errors=stderr dev/tax-admin-baseline.php "$PWD" > "$work/head.json"

"$PHP" -r '
$allowed = "/^(" . $argv[3] . ")$/";
$base = json_decode(file_get_contents($argv[1]), true);
$head = json_decode(file_get_contents($argv[2]), true);
$keep = function (array $writes) use ($allowed) {
    return array_values(array_filter($writes, function ($w) use ($allowed) {
        return preg_match($allowed, $w[0]) !== 1;
    }));
};
$failed = 0;
foreach ($base as $case => $writes) {
    if (!isset($head[$case])) {
        echo "FAIL $case: not rendered on the branch\n";
        $failed++;
        continue;
    }
    $b = $keep($writes);
    $h = $keep($head[$case]);
    if ($b !== $h) {
        echo "FAIL $case\n  base: " . json_encode($b) . "\n  head: " . json_encode($h) . "\n";
        $failed++;
    } else {
        echo "ok   $case (" . count($b) . " order-time write(s), the same on both)\n";
    }
}
if ($failed > 0) {
    echo "$failed case(s) differ outside " . $argv[3] . "\n";
    exit(1);
}
echo "render baseline OK: every case writes the same rows outside the section'"'"'s own slots\n";
' "$work/base.json" "$work/head.json" "$ALLOWED"

if [ -n "${TAX_ADMIN_BASELINE_OUT:-}" ]; then
  cp "$work/base.json" "$TAX_ADMIN_BASELINE_OUT/render-base.json"
  cp "$work/head.json" "$TAX_ADMIN_BASELINE_OUT/render-head.json"
fi
