#!/usr/bin/env bash
# Shared PrestaShop harness (TWO-25217): run the tests/integration probes
# inside a running PrestaShop container.
#
# These are NOT the offline unit suite (tests/run.php, which stubs core). Each
# probe drives the installed module against a real PrestaShop engine and a real
# cart, so it can check the assumptions the offline stubs have to make. Still
# hermetic: no browser, no network, no Two credentials.
#
# Usage: run-integration-probes.sh [probe-file-name ...]
#        (default: every *.php directly under tests/integration/)
# Required env, one of:
#   SFX          — namespacing suffix used by boot-prestashop.sh (container ps-$SFX).
#   PS_CONTAINER — an explicit container name (the dev Makefile passes prestashop).
set -euo pipefail

if [ -z "${PS_CONTAINER:-}" ]; then
  : "${SFX:?either PS_CONTAINER or SFX (namespacing suffix) must be set}"
  PS_CONTAINER="ps-$SFX"
fi
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

PROBES=("$@")
if [ "${#PROBES[@]}" -eq 0 ]; then
  while IFS= read -r probe; do
    PROBES+=("$(basename "$probe")")
  done < <(find "$REPO_ROOT/tests/integration" -maxdepth 1 -name '*.php' | sort)
fi
if [ "${#PROBES[@]}" -eq 0 ]; then
  echo "::error::no integration probes found under tests/integration/"
  exit 1
fi

# Copied to /tmp rather than run from modules/twopayment: the module tree in
# the container is the deployed module (CI stages a git-tracked copy of it),
# and a probe is not part of what gets deployed.
docker exec "$PS_CONTAINER" mkdir -p /tmp/two-integration
tar -cf - -C "$REPO_ROOT/tests/integration" --exclude=fixtures . \
  | docker exec -i "$PS_CONTAINER" tar -xf - -C /tmp/two-integration

status=0
for probe in "${PROBES[@]}"; do
  echo "--- $probe"
  if ! docker exec -u www-data "$PS_CONTAINER" \
      php -d memory_limit=512M "/tmp/two-integration/$probe"; then
    echo "::error::integration probe failed: $probe"
    status=1
  fi
done
exit $status
