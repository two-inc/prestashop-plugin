#!/usr/bin/env bash
# Shared PrestaShop CI harness (TWO-25109): run the module upgrade after the
# module files have been swapped for a newer version.
#
# Why not `bin/console prestashop:module upgrade`? That command is broken
# upstream in any CLI process where no module has been instantiated yet:
# Module::__construct refills the whole Module::$modules_cache from the DB
# on first instantiation (classes/module/Module.php:348), clobbering the
# 'upgrade' bookkeeping initUpgradeModule() just created — runUpgradeModule
# then dies on "Undefined array key number_upgraded". The web Module Manager
# only works because the admin kernel instantiates modules before upgrading.
# This script runs the exact same migration primitives ModuleManager::
# upgradeMigration() runs (initUpgradeModule + runUpgradeModule +
# upgradeModuleVersion), with the cache warmed first to dodge the bug.
# (ModuleManager's disableHooksForModule is request-scoped only — the DB
# effects of a real merchant upgrade are exactly these primitives.)
#
# Usage: upgrade-module.sh
# Required env: SFX (same namespacing suffix passed to boot-prestashop.sh)
#
# Writes NUMBER_UPGRADED=<n> to $GITHUB_ENV (when set) so the caller can
# assert a migration actually ran when it expected one — a version bump
# with a same-version no-op result would otherwise green identically to a
# genuinely-applied upgrade.
#
# Retries the scan below a bounded number of times: PrestaShop core's
# Module::getModulesOnDisk() (classes/module/Module.php) wraps its
# per-module instantiation in a bare `catch (Exception $e) {}` — any
# transient exception while instantiating the module class (observed once
# in CI, not reproduced in ~15 manual local iterations) makes the module
# silently vanish from the "on disk" list for that scan, with nothing
# logged by PrestaShop itself. That's a real intermittent upstream
# behavior, not a file-placement bug in this script (files were verified
# present on disk in the failing run). A cache-cleared retry is the
# correct response — the same fail-loud-after-N-tries shape already used
# elsewhere in this harness (WooCommerce upgrade-smoke's plugin-install
# retry), not a blind wait.
set -euo pipefail

: "${SFX:?SFX (namespacing suffix) must be set}"

attempt=0
max_attempts=3
number_upgraded=""
while [ "$attempt" -lt "$max_attempts" ]; do
  attempt=$((attempt + 1))
  if number_upgraded=$(docker exec -u www-data "ps-$SFX" php -d memory_limit=512M -r '
    require "/var/www/html/config/config.inc.php";

    // Warm Module::$modules_cache BEFORE initUpgradeModule (see header).
    Module::getInstanceByName("twopayment");

    $found = false;
    foreach (Module::getModulesOnDisk() as $m) {
        if ($m->name !== "twopayment") {
            continue;
        }
        $found = true;
        $numberUpgraded = 0;
        if (Module::initUpgradeModule($m)) {
            $instance = Module::getInstanceByName("twopayment");
            $result = $instance->runUpgradeModule();
            $errors = $instance->getErrors();
            if (!empty($errors)) {
                foreach ($errors as $e) {
                    fwrite(STDERR, "upgrade error: " . $e . "\n");
                }
                exit(1);
            }
            if (empty($result["success"])) {
                fwrite(STDERR, "module upgrade did not succeed\n");
                exit(1);
            }
            $numberUpgraded = $result["number_upgraded"];
            fwrite(STDERR, sprintf(
                "applied %d upgrade script(s), upgraded to %s\n",
                $result["number_upgraded"],
                $result["upgraded_to"]
            ));
        } else {
            fwrite(STDERR, "no upgrade scripts needed\n");
        }
        // Mirror ModuleManager::upgradeMigration: align the DB version with
        // the on-disk version even when no upgrade script covers the last step.
        Module::upgradeModuleVersion("twopayment", $m->version);
        // Sole stdout line: the caller reads this via command substitution,
        // everything else above goes to stderr so it stays out of the value.
        echo $numberUpgraded;
        break;
    }
    if (!$found) {
        fwrite(STDERR, "module twopayment not found on disk\n");
        exit(1);
    }
  '); then
    break
  fi
  echo "::warning::upgrade-module.sh: attempt $attempt/$max_attempts failed (module not surfaced by getModulesOnDisk — see script header); retrying after cache clear" >&2
  docker exec "ps-$SFX" bash -c "rm -rf /var/www/html/var/cache/*"
  if [ "$attempt" -ge "$max_attempts" ]; then
    echo "::error::upgrade-module.sh: module upgrade scan failed after $max_attempts attempts"
    exit 1
  fi
  sleep 2
done

docker exec "ps-$SFX" bash -c "rm -rf /var/www/html/var/cache/*"
echo "module twopayment upgrade complete (number_upgraded=$number_upgraded)"
if [ -n "${GITHUB_ENV:-}" ]; then
  echo "NUMBER_UPGRADED=$number_upgraded" >> "$GITHUB_ENV"
fi
