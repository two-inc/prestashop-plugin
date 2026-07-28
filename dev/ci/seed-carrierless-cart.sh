#!/usr/bin/env bash
# Shared PrestaShop harness (TWO-25217): stand up the carrier-less shipping
# cart shape that the optional "Default shipping tax code" (TWO-25200) exists
# for, on a running PrestaShop.
#
# Two things are needed, and neither can be faked from the database:
#   1. a module that INJECTS a delivery option belonging to no carrier
#      (tests/integration/fixtures/twocarrierlesstest) - core discards the
#      whole option list on its own no-carrier sentinel, and
#      getOrderTotal(ONLY_SHIPPING) derives from that same list, so breaking
#      carrier coverage yields shipping of 0 and exercises nothing;
#   2. a customer/address/product/cart carrying that delivery selection
#      (dev/ci/seed-carrierless-cart.php).
#
# Every write goes through ObjectModel / Configuration, never SQL - unlike
# seed-two-config.sh's Configuration-only writes, this creates real records
# (tax rules group, customer, address, cart), and hand-written SQL for those
# would drift from core's schema and skip its own side effects.
#
# Idempotent: safe to re-run against the same shop.
#
# Usage: seed-carrierless-cart.sh
# Required env, one of:
#   SFX          — namespacing suffix used by boot-prestashop.sh; the container
#                  is then ps-$SFX (how CI calls this).
#   PS_CONTAINER — an explicit container name, for a shop this harness did not
#                  boot (how the dev Makefile calls this: PS_CONTAINER=prestashop).
set -euo pipefail

if [ -z "${PS_CONTAINER:-}" ]; then
  : "${SFX:?either PS_CONTAINER or SFX (namespacing suffix) must be set}"
  PS_CONTAINER="ps-$SFX"
fi
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE_SRC="$REPO_ROOT/tests/integration/fixtures/twocarrierlesstest"
[ -d "$FIXTURE_SRC" ] || { echo "::error::fixture module missing at $FIXTURE_SRC"; exit 1; }

# Copied in rather than symlinked/mounted: PrestaShop only discovers modules
# under its own modules/ directory, and the local dev shop bind-mounts the repo
# at modules/twopayment (so the fixture is visible there, but at a path PS will
# never scan for a module named twocarrierlesstest).
docker exec "$PS_CONTAINER" mkdir -p /var/www/html/modules/twocarrierlesstest
tar -cf - -C "$FIXTURE_SRC" . \
  | docker exec -i "$PS_CONTAINER" tar -xf - -C /var/www/html/modules/twocarrierlesstest
docker exec "$PS_CONTAINER" chown -R www-data:www-data /var/www/html/modules/twocarrierlesstest

# Install is not idempotent (a second install reports failure), so only
# install when the module is not already registered. The fixture ships inert
# — it does nothing until TWO_CARRIERLESS_TEST_GROSS is positive, which the
# seed script below sets — so installing it can never affect an unrelated run.
if ! docker exec -u www-data "$PS_CONTAINER" php -d memory_limit=512M -r '
require "/var/www/html/config/config.inc.php";
exit(Module::isInstalled("twocarrierlesstest") ? 0 : 1);
'; then
  docker exec -u www-data "$PS_CONTAINER" bash -c \
    "cd /var/www/html && php -d memory_limit=512M bin/console prestashop:module install twocarrierlesstest"
fi

tar -cf - -C "$REPO_ROOT/dev/ci" seed-carrierless-cart.php \
  | docker exec -i "$PS_CONTAINER" tar -xf - -C /tmp
docker exec -u www-data "$PS_CONTAINER" php -d memory_limit=512M /tmp/seed-carrierless-cart.php
docker exec "$PS_CONTAINER" bash -c "rm -rf /var/www/html/var/cache/*"
