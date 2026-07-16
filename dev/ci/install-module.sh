#!/usr/bin/env bash
# Shared PrestaShop CI harness (TWO-25109): copy a module source tree into
# the running PrestaShop container and install it via the PS console.
#
# Usage: install-module.sh <module-source-dir>
# Required env: SFX (same namespacing suffix passed to boot-prestashop.sh)
set -euo pipefail

: "${SFX:?SFX (namespacing suffix) must be set}"
SRC="${1:?usage: install-module.sh <module-source-dir>}"

docker exec "ps-$SFX" mkdir -p /var/www/html/modules/twopayment
tar -cf - -C "$SRC" . \
  | docker exec -i "ps-$SFX" tar -xf - -C /var/www/html/modules/twopayment
docker exec "ps-$SFX" chown -R www-data:www-data /var/www/html/modules/twopayment

# Fail-loud install: a fatal in module code makes the console exit non-zero.
docker exec -u www-data "ps-$SFX" bash -c \
  "cd /var/www/html && php -d memory_limit=512M bin/console prestashop:module install twopayment"
docker exec "ps-$SFX" bash -c "rm -rf /var/www/html/var/cache/*"

echo "module twopayment installed from $SRC"
