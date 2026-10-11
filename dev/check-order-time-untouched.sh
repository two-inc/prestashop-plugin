#!/usr/bin/env bash
# Order-time isolation guard for the "Tax codes for 0% lines" admin rework (TWO-26153).
#
# That change only re-presents an admin section, so nothing an order reads may move. This fails when the working
# tree, against its merge base with the base branch, changes:
#   - any file under classes/ other than the new classes/TwoTaxCodeSection.php
#   - any file under controllers/front/ or override/
#   - any line of twopayment.php outside the admin set dev/order-time-guard.php lists
#   - any order-time golden under tests/fixtures/
#
# Usage: dev/check-order-time-untouched.sh [base ref]   (default: origin/staging)
set -euo pipefail

cd "$(dirname "$0")/.."
BASE_REF="${1:-origin/staging}"
BASE="$(git merge-base "$BASE_REF" HEAD)"
status=0

while IFS= read -r file; do
  case "$file" in
    classes/TwoTaxCodeSection.php) ;;
    classes/*|controllers/front/*|override/*)
      echo "::error::order-time file changed: $file"
      status=1
      ;;
  esac
done < <(git diff --name-only "$BASE" -- classes controllers/front override; git ls-files --others --exclude-standard -- classes controllers/front override)

# The order-time goldens stay as the base branch wrote them.
while IFS= read -r file; do
  echo "::error::order-time golden changed: $file"
  status=1
done < <(git diff --name-only "$BASE" -- 'tests/fixtures/*golden*')

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
git show "$BASE:twopayment.php" > "$work/base.php"
if ! git diff -U0 --no-color "$BASE" -- twopayment.php | php dev/order-time-guard.php "$work/base.php" twopayment.php; then
  echo "::error::twopayment.php changes reach outside the admin methods"
  status=1
fi

if [ "$status" -eq 0 ]; then
  echo "order-time guard OK against $BASE_REF ($BASE)"
fi
exit $status
