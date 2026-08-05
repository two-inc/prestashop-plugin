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
DB_IMAGE="mariadb:10.11"
# Optional (TWO-25110): publish the storefront to a host port so a
# browser-driven caller (Playwright, running on the runner host rather
# than via `docker exec`) can reach it. Unset by default so the install/
# upgrade-smoke jobs (docker-exec-only) get the exact same `docker run`
# invocation as before this was added.
PS_HOST_PORT="${PS_HOST_PORT:-}"
# Optional (TWO-25110): forwarded straight to the module's existing
# TWO_API_BASE_URL dev-mode override (twopayment.php getDevEnvOverride,
# gated on _PS_MODE_DEV_ i.e. PS_DEV_MODE=1). Unset by default, matching
# every other job's behaviour (module falls back to the real
# api.sandbox.two.inc host). The e2e job points this at an in-container
# stub (dev/ci/start-two-api-stub.sh) that answers only the API-key
# verification endpoint and refuses everything else, so the module's
# checkout-media priming calls (merchant terms/FX-rate refresh, fired on
# every checkout page view) fail fast instead of making live calls to
# Two's real sandbox from public CI with a throwaway key.
TWO_API_BASE_URL="${TWO_API_BASE_URL:-}"

# Docker Hub intermittently 429s GitHub-hosted runners; every other network
# op in this harness is retry-wrapped (curl --retry, ls-remote fail-loud) —
# pulls were the one bare-single-attempt exception. Bounded retry, fail loud
# after exhausting it rather than leaving `docker run` to surface a raw pull
# error.
pull_with_retry() {
  local image="$1" n=0
  until docker pull "$image"; do
    n=$((n + 1))
    if [ "$n" -ge 5 ]; then
      echo "::error::failed to pull $image after 5 attempts"
      return 1
    fi
    sleep $((n * 5))
  done
}

pull_with_retry "$DB_IMAGE"
pull_with_retry "$PS_IMAGE"

docker network create "psnet-$SFX"

docker run --detach --name "psdb-$SFX" --network "psnet-$SFX" \
  -e MYSQL_ROOT_PASSWORD=admin -e MYSQL_DATABASE=prestashop \
  --health-cmd="mysqladmin ping -h localhost -uroot -padmin" \
  --health-interval=5s --health-timeout=5s --health-retries=30 \
  "$DB_IMAGE"

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

# PS_DOMAIN=localhost (optionally :$PS_HOST_PORT) so in-container curls to
# http://localhost/ hit the canonical shop host — a mismatched Host header
# trips a canonical-domain 301 that a follow-redirects curl -f would treat
# as success having rendered nothing (gotcha inherited from the
# WooCommerce upgrade-smoke).
PS_DOMAIN_VALUE="localhost"
PORT_ARGS=()
if [ -n "$PS_HOST_PORT" ]; then
  PS_DOMAIN_VALUE="localhost:${PS_HOST_PORT}"
  PORT_ARGS=(-p "127.0.0.1:${PS_HOST_PORT}:80")
fi

ENV_ARGS=()
if [ -n "$TWO_API_BASE_URL" ]; then
  ENV_ARGS=(-e "TWO_API_BASE_URL=$TWO_API_BASE_URL")
fi

docker run --detach --name "ps-$SFX" --network "psnet-$SFX" "${PORT_ARGS[@]}" "${ENV_ARGS[@]}" \
  -e DB_SERVER="psdb-$SFX" -e DB_NAME=prestashop \
  -e DB_USER=root -e DB_PASSWD=admin \
  -e PS_DOMAIN="$PS_DOMAIN_VALUE" -e PS_INSTALL_AUTO=1 -e PS_DEV_MODE="$PS_DEV_MODE" \
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

# Storefront must render before any module work starts. Explicit status-code
# check, not `curl -f`: -f only fails on 4xx/5xx, so a canonical-domain 301
# or maintenance 302 would exit 0 having rendered nothing.
code=$(docker exec "ps-$SFX" curl -s -o /dev/null -w '%{http_code}' -H "Host: $PS_DOMAIN_VALUE" http://localhost/)
if [ "$code" != "200" ]; then
  echo "::error::storefront did not return 200 (got '$code')"
  exit 1
fi

echo "PrestaShop ($PS_IMAGE) booted and installed."
