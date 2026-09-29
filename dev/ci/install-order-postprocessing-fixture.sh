#!/usr/bin/env bash
# Install the actionTwoOrderPostprocessing subscriber fixture (TWO-26092) into a
# running PrestaShop, for tests/integration/order-postprocessing-hook.php.
#
# The fixture ships inert (TWO_OPP_TEST_MODE unset), so installing it changes
# nothing for any other probe.
#
# Idempotent. Required env, one of:
#   SFX          - namespacing suffix used by boot-prestashop.sh (container ps-$SFX).
#   PS_CONTAINER - an explicit container name (the dev Makefile passes prestashop).
set -euo pipefail

if [ -z "${PS_CONTAINER:-}" ]; then
  : "${SFX:?either PS_CONTAINER or SFX (namespacing suffix) must be set}"
  PS_CONTAINER="ps-$SFX"
fi
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE_SRC="$REPO_ROOT/tests/integration/fixtures/twoorderpostprocessingtest"
[ -d "$FIXTURE_SRC" ] || { echo "::error::fixture module missing at $FIXTURE_SRC"; exit 1; }

docker exec "$PS_CONTAINER" mkdir -p /var/www/html/modules/twoorderpostprocessingtest
tar -cf - -C "$FIXTURE_SRC" . \
  | docker exec -i "$PS_CONTAINER" tar -xf - -C /var/www/html/modules/twoorderpostprocessingtest
docker exec "$PS_CONTAINER" chown -R www-data:www-data /var/www/html/modules/twoorderpostprocessingtest

# Install is not idempotent (a second install reports failure), so only install when absent.
if ! docker exec -u www-data "$PS_CONTAINER" php -d memory_limit=512M -r '
require "/var/www/html/config/config.inc.php";
exit(Module::isInstalled("twoorderpostprocessingtest") ? 0 : 1);
'; then
  docker exec -u www-data "$PS_CONTAINER" bash -c \
    "cd /var/www/html && php -d memory_limit=512M bin/console prestashop:module install twoorderpostprocessingtest"
fi
docker exec "$PS_CONTAINER" bash -c "rm -rf /var/www/html/var/cache/*"
