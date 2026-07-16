#!/usr/bin/env bash
# Shared PrestaShop CI harness (TWO-25109): boot MariaDB + PrestaShop with
# auto-install and wait for the install to complete. Used by both the
# install-smoke and upgrade-smoke jobs in .github/workflows/smoke.yml.
#
# No blind sleeps: every wait is a bounded poll that fails loud on timeout
# (pattern from woocommerce-plugin upgrade-smoke / magento TWO-24998).
#
# Required env:
#   SFX       — namespacing suffix so concurrent runs on a shared runner
#               can't collide on container/network names.
# Optional env:
#   PS_IMAGE     — PrestaShop image (default mirrors docker-compose.yml).
#   PS_DEV_MODE  — 1 (default) for debug-mode strictness (extra hook/method
#                  validation, verbose fatals) when installing HEAD; 0 for
#                  merchant-realistic production mode. The upgrade-smoke job
#                  uses 0 because released tags must install the way a
#                  merchant runs them — e.g. tag 2.2.2 registers a hook
#                  without its method, which only throws in debug mode.
set -euo pipefail

: "${SFX:?SFX (namespacing suffix) must be set}"
PS_IMAGE="${PS_IMAGE:-prestashop/prestashop:8-apache}"
PS_DEV_MODE="${PS_DEV_MODE:-1}"

docker network create "psnet-$SFX"

docker run --detach --name "psdb-$SFX" --network "psnet-$SFX" \
  -e MYSQL_ROOT_PASSWORD=admin -e MYSQL_DATABASE=prestashop \
  --health-cmd="mysqladmin ping -h localhost -uroot -padmin" \
  --health-interval=5s --health-timeout=5s --health-retries=30 \
  mariadb:10.11

tries=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' "psdb-$SFX")" = "healthy" ]; do
  tries=$((tries + 1))
  if [ "$tries" -ge 60 ]; then
    echo "::error::database never became healthy"
    docker logs "psdb-$SFX" || true
    exit 1
  fi
  sleep 2
done

# PS_DOMAIN=localhost so in-container curls to http://localhost/ hit the
# canonical shop host — a mismatched Host header trips a canonical-domain
# 301 that a follow-redirects curl -f would treat as success having
# rendered nothing (gotcha inherited from the WooCommerce upgrade-smoke).
docker run --detach --name "ps-$SFX" --network "psnet-$SFX" \
  -e DB_SERVER="psdb-$SFX" -e DB_NAME=prestashop \
  -e DB_USER=root -e DB_PASSWD=admin \
  -e PS_DOMAIN=localhost -e PS_INSTALL_AUTO=1 -e PS_DEV_MODE="$PS_DEV_MODE" \
  -e PS_LANGUAGE=en -e PS_COUNTRY=NO -e PS_ALL_LANGUAGES=0 \
  -e PS_DEMO_MODE=0 -e PS_FOLDER_ADMIN=admin-dev \
  -e ADMIN_MAIL=exampleuser@two.inc -e ADMIN_PASSWD=examplepassword123 \
  "$PS_IMAGE"

# Auto-install takes 60-120s. Same completion signals the dev Makefile
# polls: config written AND the install/ directory gone.
tries=0
until docker exec "ps-$SFX" bash -c \
    '{ [ -f /var/www/html/config/settings.inc.php ] || [ -f /var/www/html/app/config/parameters.php ]; } && [ ! -d /var/www/html/install ]' \
    2>/dev/null; do
  tries=$((tries + 1))
  if [ "$tries" -ge 120 ]; then
    echo "::error::PrestaShop auto-install never completed"
    docker logs "ps-$SFX" || true
    exit 1
  fi
  sleep 3
done

# var/ permissions fix mirrored from the dev Makefile (admin 500 fix).
docker exec "ps-$SFX" bash -c \
  "chown -R www-data:www-data /var/www/html/var && chmod -R 775 /var/www/html/var"

# Storefront must render before any module work starts.
docker exec "ps-$SFX" curl -sf http://localhost/ -o /dev/null

echo "PrestaShop ($PS_IMAGE) booted and installed."
