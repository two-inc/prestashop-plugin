#!/usr/bin/env bash
# Shared PrestaShop CI harness (TWO-25109): assert the Two module is in a
# healthy installed state — active, at the expected version, registered on
# the paymentOptions hook, boots without a PHP fatal, and the storefront
# still renders.
#
# Usage: assert-module-healthy.sh <expected-module-version>
# Required env: SFX (same namespacing suffix passed to boot-prestashop.sh)
set -euo pipefail

: "${SFX:?SFX (namespacing suffix) must be set}"
EXPECTED_VERSION="${1:?usage: assert-module-healthy.sh <expected-module-version>}"

psql() {
  docker exec "psdb-$SFX" mysql -uroot -padmin -N -B prestashop -e "$1"
}

active=$(psql "SELECT active FROM ps_module WHERE name='twopayment'")
if [ "$active" != "1" ]; then
  echo "::error::module twopayment not active (active='$active')"
  exit 1
fi

version=$(psql "SELECT version FROM ps_module WHERE name='twopayment'")
if [ "$version" != "$EXPECTED_VERSION" ]; then
  echo "::error::module version '$version' != expected '$EXPECTED_VERSION'"
  exit 1
fi

# The Two payment method registers = the module is hooked on paymentOptions.
hooked=$(psql "SELECT COUNT(*) FROM ps_hook_module hm
  JOIN ps_hook h ON h.id_hook = hm.id_hook
  JOIN ps_module m ON m.id_module = hm.id_module
  WHERE m.name='twopayment' AND h.name='paymentOptions'")
if [ -z "$hooked" ] || [ "$hooked" -lt 1 ]; then
  echo "::error::module not registered on paymentOptions hook (count='$hooked')"
  exit 1
fi

# Boot the PS kernel and instantiate the module class — this loads
# twopayment.php + its require chain, catching PHP fatals the DB checks
# above cannot see.
docker exec -u www-data "ps-$SFX" php -d memory_limit=512M -r '
  require "/var/www/html/config/config.inc.php";
  $m = Module::getInstanceByName("twopayment");
  if (!($m instanceof PaymentModule)) {
    fwrite(STDERR, "module did not instantiate as a PaymentModule\n");
    exit(1);
  }
  if (!$m->active) {
    fwrite(STDERR, "module instance reports inactive\n");
    exit(1);
  }
  echo "module boots: " . $m->version . "\n";
'

# Storefront renders without a fatal. Explicit status-code check, not
# `curl -f`: -f only fails on 4xx/5xx, so a canonical-domain 301 or
# maintenance 302 would exit 0 having rendered nothing.
code=$(docker exec "ps-$SFX" curl -s -o /dev/null -w '%{http_code}' http://localhost/)
if [ "$code" != "200" ]; then
  echo "::error::storefront did not return 200 (got '$code')"
  exit 1
fi

echo "module twopayment healthy at version $EXPECTED_VERSION"
