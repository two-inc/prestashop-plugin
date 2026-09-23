#!/usr/bin/env bash
# Carrier-less shipping matrix (TWO-25938); modes and configs are in
# tests/integration/README.md. Requires seed-carrierless-cart.sh to have run first.
set -euo pipefail

if [ -z "${PS_CONTAINER:-}" ]; then
  : "${SFX:?either PS_CONTAINER or SFX (namespacing suffix) must be set}"
  PS_CONTAINER="ps-$SFX"
fi
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OVERRIDE_DEST=/var/www/html/override/modules/twopayment/twopayment.php
TAX_CODE_SWITCH=/var/www/html/modules/twopayment/dev/enable-default-shipping-tax-code

CONFIGS=(1 2 3)
# Configs 4/5 need all three, kept outside this repo: the override, a shim dir
# (Cart.php replacing the fixture's Cart override, optional install.php /
# uninstall.php run in the shop) and the override's rate config key.
if [ -n "${MERCHANT_OVERRIDE_PATH:-}" ]; then
  [ -f "$MERCHANT_OVERRIDE_PATH" ] || { echo "::error::MERCHANT_OVERRIDE_PATH not a file: $MERCHANT_OVERRIDE_PATH"; exit 1; }
  [ -f "${MERCHANT_SHIM_PATH:-}/Cart.php" ] || { echo "::error::MERCHANT_SHIM_PATH must be a dir holding Cart.php"; exit 1; }
  : "${MERCHANT_RATE_CONFIG_KEY:?MERCHANT_RATE_CONFIG_KEY must be set with MERCHANT_OVERRIDE_PATH}"
  CONFIGS+=(4a 4b 5a 5b)
fi

docker exec "$PS_CONTAINER" mkdir -p /tmp/two-integration
tar -cf - -C "$REPO_ROOT/tests/integration" --exclude=fixtures . \
  | docker exec -i "$PS_CONTAINER" tar -xf - -C /tmp/two-integration

OVERRIDE_BACKUP="$OVERRIDE_DEST.matrix-backup"
CART_DEST=/var/www/html/override/classes/Cart.php
CART_BACKUP="$CART_DEST.matrix-backup"
SHIM_DEST=/tmp/two-merchant-shim
CONFIG_KEYS='["TWO_CARRIERLESS_TEST_GROSS","TWO_CARRIERLESS_TEST_NET","TWO_CARRIERLESS_TEST_MODE","TWO_CARRIERLESS_TEST_SURCHARGE","PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP"'
[ -z "${MERCHANT_RATE_CONFIG_KEY:-}" ] || CONFIG_KEYS+=",\"$MERCHANT_RATE_CONFIG_KEY\""
CONFIG_KEYS+=']'

config_snapshot=$(docker exec -u www-data "$PS_CONTAINER" php -r '
require "/var/www/html/config/config.inc.php";
$snapshot = array();
foreach (json_decode($argv[1], true) as $key) {
    $snapshot[$key] = Configuration::hasKey($key) ? Configuration::get($key) : null;
}
echo json_encode($snapshot);
' "$CONFIG_KEYS")
if docker exec "$PS_CONTAINER" grep -qs _TWO_ENABLE_DEFAULT_SHIPPING_TAX_CODE_ /var/www/html/config/defines_custom.inc.php; then
  tax_code_was_on=1
else
  tax_code_was_on=0
fi

run_shim_script() {
  if docker exec "$PS_CONTAINER" test -f "$SHIM_DEST/$1"; then
    docker exec -u www-data "$PS_CONTAINER" php "$SHIM_DEST/$1"
  fi
}
# PrestaShop resolves overrides through its class index, so the file alone is not enough.
clear_class_index() {
  docker exec "$PS_CONTAINER" bash -c "rm -f /var/www/html/var/cache/*/class_index.php"
}
install_merchant_override() {
  [ "$override_copied" = 0 ] || return 0
  # Set before mutating, so restore also cleans up a setup that failed partway.
  override_copied=1
  docker exec "$PS_CONTAINER" mkdir -p "$(dirname "$OVERRIDE_DEST")"
  docker cp "$MERCHANT_OVERRIDE_PATH" "$PS_CONTAINER:$OVERRIDE_DEST" >/dev/null
  docker exec "$PS_CONTAINER" rm -rf "$SHIM_DEST"
  docker cp "$MERCHANT_SHIM_PATH/." "$PS_CONTAINER:$SHIM_DEST" >/dev/null
  # Only one Cart override can load, so the shim's replaces the fixture's for these cells.
  docker exec "$PS_CONTAINER" cp -p "$CART_DEST" "$CART_BACKUP"
  docker exec "$PS_CONTAINER" cp "$SHIM_DEST/Cart.php" "$CART_DEST"
  docker exec "$PS_CONTAINER" chown -R www-data:www-data "$OVERRIDE_DEST" "$SHIM_DEST" "$CART_DEST"
  run_shim_script install.php
  clear_class_index
}
attempt() {
  "$@" || { echo "::error::cleanup step failed (exit $?): $*" >&2; cleanup_failed=1; }
}
# Leave the shop as it was before this run, so the probes still pass afterwards.
restore() {
  local rc=$?
  set +e
  if [ "$override_copied" = 1 ]; then
    attempt run_shim_script uninstall.php
    attempt docker exec "$PS_CONTAINER" rm -rf "$OVERRIDE_DEST" "$SHIM_DEST"
    attempt docker exec "$PS_CONTAINER" rmdir -p --ignore-fail-on-non-empty "$(dirname "$OVERRIDE_DEST")"
    if docker exec "$PS_CONTAINER" test -e "$CART_BACKUP"; then
      attempt docker exec "$PS_CONTAINER" mv "$CART_BACKUP" "$CART_DEST"
    fi
  fi
  if docker exec "$PS_CONTAINER" test -e "$OVERRIDE_BACKUP"; then
    attempt docker exec "$PS_CONTAINER" mv "$OVERRIDE_BACKUP" "$OVERRIDE_DEST"
  fi
  attempt clear_class_index
  attempt docker exec "$PS_CONTAINER" bash "$TAX_CODE_SWITCH" $([ "$tax_code_was_on" = 1 ] || echo --reset) >/dev/null
  attempt docker exec -u www-data "$PS_CONTAINER" php -r '
require "/var/www/html/config/config.inc.php";
foreach (json_decode($argv[1], true) as $key => $value) {
    if ($value === null) {
        Configuration::deleteByName($key);
    } else {
        Configuration::updateValue($key, $value);
    }
}
' "$config_snapshot"
  [ "$rc" != 0 ] || rc=$cleanup_failed
  exit "$rc"
}
override_copied=0
cleanup_failed=0
trap restore EXIT

# A developer's own override is moved aside, not deleted, so configs 1-3 run without it.
if docker exec "$PS_CONTAINER" test -e "$OVERRIDE_DEST"; then
  docker exec "$PS_CONTAINER" mv "$OVERRIDE_DEST" "$OVERRIDE_BACKUP"
fi
clear_class_index

rows=()
status=0
for config in "${CONFIGS[@]}"; do
  case "$config" in 4*|5*) install_merchant_override ;; esac
  # Configs that set the default code do so with its admin field revealed, as a merchant would.
  case "$config" in
    2|3|5*) docker exec "$PS_CONTAINER" bash "$TAX_CODE_SWITCH" >/dev/null ;;
    *) docker exec "$PS_CONTAINER" bash "$TAX_CODE_SWITCH" --reset >/dev/null ;;
  esac
  modes=(A B C)
  # D's anomaly is on a product line, which the merchant override does not touch.
  case "$config" in 1|2|3) modes+=(D) ;; esac
  for mode in "${modes[@]}"; do
    rc=0
    out=$(docker exec -u www-data -e MERCHANT_RATE_CONFIG_KEY="${MERCHANT_RATE_CONFIG_KEY:-}" "$PS_CONTAINER" \
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

echo
echo "| cell | shape | cart BOTH incl/excl | SHIPPING_FEE gross/net/tax@rate | order gross/net/tax | outcome |"
echo "|---|---|---|---|---|---|"
for row in "${rows[@]}"; do
  echo "| ${row//$'\t'/ | } |"
done
[ -n "${MERCHANT_OVERRIDE_PATH:-}" ] || echo "(configs 4/5 skipped: MERCHANT_OVERRIDE_PATH not set)"
exit $status
