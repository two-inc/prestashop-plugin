#!/usr/bin/env bash
# Carrier-less shipping matrix (TWO-25938); modes, configs and invariants are in
# tests/integration/README.md. Boots its own PrestaShop per config group and removes
# it on exit, so it never touches an existing shop.
#
# Optional env: PS_IMAGE (see boot-prestashop.sh); MERCHANT_OVERRIDE_PATH,
# MERCHANT_SHIM_PATH and MERCHANT_RATE_CONFIG_KEY together add configs 4/5.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# The UID keeps one user's sweep off another's runs on a shared Docker daemon.
PREFIX="carrierless-matrix-$(id -u)"
# The harness scripts would target PS_CONTAINER over SFX; this run only ever uses its own.
unset PS_CONTAINER
# The cells never call Two; a port nothing listens on makes checkout-media priming fail fast.
# Assigned unconditionally: the Makefile exports its sandbox URL.
export TWO_API_BASE_URL=http://127.0.0.1:1

CONFIG_GROUPS=("1 2 3")
if [ -n "${MERCHANT_OVERRIDE_PATH:-}" ]; then
  [ -f "$MERCHANT_OVERRIDE_PATH" ] || { echo "::error::MERCHANT_OVERRIDE_PATH not a file: $MERCHANT_OVERRIDE_PATH"; exit 1; }
  [ -f "${MERCHANT_SHIM_PATH:-}/Cart.php" ] || { echo "::error::MERCHANT_SHIM_PATH must be a dir holding Cart.php"; exit 1; }
  : "${MERCHANT_RATE_CONFIG_KEY:?MERCHANT_RATE_CONFIG_KEY must be set with MERCHANT_OVERRIDE_PATH}"
  # Only one Cart override can load, so the merchant configs get a shop of their own.
  CONFIG_GROUPS+=("4a 4b 5a 5b")
fi

# Removes every container and network of run $1 (a runner PID).
remove_run() {
  docker ps -aq --filter "name=-$PREFIX-$1-" | xargs -r docker rm -f >/dev/null
  docker network ls -q --filter "name=-$PREFIX-$1-" | xargs -r docker network rm >/dev/null
}
TMP_ROOT="${TMPDIR:-/tmp}"
# A run killed with SIGKILL never reached its trap; its PID is gone, so its leftovers go now.
for pid in $({ docker ps -a --format '{{.Names}}'; docker network ls --format '{{.Name}}'; } \
    | sed -n "s/^[a-z]*-$PREFIX-\([0-9]*\)-.*/\1/p" | sort -u); do
  if [ "$pid" = "$$" ] || ! ps -p "$pid" >/dev/null; then
    remove_run "$pid"
  fi
done
for dir in "$TMP_ROOT/$PREFIX-"*; do
  pid=${dir##*/"$PREFIX"-}
  pid=${pid%%.*}
  if [ -O "$dir" ] && ! ps -p "$pid" >/dev/null; then
    rm -rf "$dir"
  fi
done

rows=()
print_table() {
  [ "${#rows[@]}" -gt 0 ] || return 0
  echo
  echo "| cell | shape | cart BOTH incl/excl | SHIPPING_FEE gross/net/tax@rate | order gross/net/tax | outcome |"
  echo "|---|---|---|---|---|---|"
  for row in "${rows[@]}"; do
    echo "| ${row//$'\t'/ | } |"
  done
  [ -n "${MERCHANT_OVERRIDE_PATH:-}" ] || echo "(configs 4/5 skipped: MERCHANT_OVERRIDE_PATH not set)"
}

STAGE=$(mktemp -d "$TMP_ROOT/$PREFIX-$$.XXXXXX")
trap 'rm -rf "$STAGE"; print_table; remove_run $$' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
(cd "$REPO_ROOT" && git ls-files -z --cached --others --exclude-standard) \
  | tar --null -cf - -C "$REPO_ROOT" -T - | tar -xf - -C "$STAGE"

inject_merchant_override() {
  local ps=$1 override=/var/www/html/override/modules/twopayment/twopayment.php
  docker exec "$ps" mkdir -p "$(dirname "$override")"
  docker cp "$MERCHANT_OVERRIDE_PATH" "$ps:$override" >/dev/null
  docker cp "$MERCHANT_SHIM_PATH/." "$ps:/tmp/two-merchant-shim" >/dev/null
  docker exec "$ps" cp /tmp/two-merchant-shim/Cart.php /var/www/html/override/classes/Cart.php
  docker exec "$ps" chown -R www-data:www-data /var/www/html/override /tmp/two-merchant-shim
  if [ -f "$MERCHANT_SHIM_PATH/install.php" ]; then
    docker exec -u www-data "$ps" php /tmp/two-merchant-shim/install.php
  fi
  # PrestaShop resolves overrides through its class index, so the files alone are not enough.
  docker exec "$ps" bash -c "rm -f /var/www/html/var/cache/*/class_index.php"
}

status=0
for group in "${!CONFIG_GROUPS[@]}"; do
  export SFX="$PREFIX-$$-$group"
  ps="ps-$SFX"
  "$STAGE/dev/ci/boot-prestashop.sh"
  "$STAGE/dev/ci/install-module.sh" "$STAGE"
  "$STAGE/dev/ci/seed-two-config.sh"
  "$STAGE/dev/ci/seed-carrierless-cart.sh"
  [ "$group" = 0 ] || inject_merchant_override "$ps"
  docker exec "$ps" mkdir -p /tmp/two-integration
  tar -cf - -C "$STAGE/tests/integration" --exclude=fixtures . \
    | docker exec -i "$ps" tar -xf - -C /tmp/two-integration

  for config in ${CONFIG_GROUPS[$group]}; do
    modes=(A B C)
    # D's anomaly is on a product line, which the merchant override does not touch.
    [ "$group" != 0 ] || modes+=(D)
    for mode in "${modes[@]}"; do
      rc=0
      out=$(docker exec -u www-data -e MERCHANT_RATE_CONFIG_KEY="${MERCHANT_RATE_CONFIG_KEY:-}" "$ps" \
        php -d memory_limit=512M /tmp/two-integration/matrix/carrierless-shipping-cell.php "$mode" "$config" || echo "EXIT $?") || true
      if [[ "$out" =~ EXIT\ ([0-9]+)$ ]]; then
        rc=${BASH_REMATCH[1]}
        status=1
      fi
      grep -v $'^ROW\t' <<<"$out" || true
      row=$(grep $'^ROW\t' <<<"$out" | cut -f2- || true)
      # A cell that died before printing its ROW still gets one, so the table stays complete.
      [ -n "$row" ] || row="$mode$config"$'\tCRASHED (exit '"$rc"$')\t-\t-\t-\t-'
      rows+=("$row")
    done
  done
  remove_run $$
done

exit $status
