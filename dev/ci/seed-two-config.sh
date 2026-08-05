#!/usr/bin/env bash
# Shared PrestaShop CI harness (TWO-25110): seed the Two module's merchant
# config directly in the database so the payment option renders at
# checkout, without a live network call to Two's verify_api_key endpoint.
#
# Why not dev/configure.php? That script calls the real Two API to verify
# the key and populate merchant_short_name/merchant_id — exactly what a
# real merchant setup does. This repo's CI is deliberately hermetic (no
# secrets, no live Two API dependency — see tests.yml/smoke.yml), so this
# script writes the same config keys directly, bypassing the live call.
# It's for e2e/UI-rendering assertions only. The e2e workflow also points
# TWO_API_BASE_URL at an in-container stub (dev/ci/start-two-api-stub.sh)
# that answers ONLY /v1/merchant/verify_api_key and refuses everything
# else, so the checkout-media priming calls (merchant terms/FX-rate
# refresh, fired on every checkout page view) still fail fast rather than
# hitting Two's real sandbox from public CI with this dummy key. That one
# endpoint has to answer since TWO-25326: the module withholds the payment
# option entirely - and the company-search control with it - while the
# stored key cannot be verified, unreachable API included, so seeding
# PS_TWO_API_KEY_VERIFIED below is no longer sufficient on its own.
#
# Deliberately NOT seeded: PS_TWO_MERCHANT_MIN_ORDER / the platform
# minimum-order config (see isTwoMinimumOrderSatisfied /
# getPlatformMinimumOrder in twopayment.php). Left unset so that gate
# short-circuits to "satisfied" — it has a fail-closed branch on
# FX-conversion with no cached rate, which would hide the payment option
# and break the e2e suite's "option renders" assertion for a reason
# unrelated to what that test is checking. If you add seeding for either
# key here, re-verify that assertion still passes.
#
# Usage: seed-two-config.sh
# Required env: SFX (same namespacing suffix passed to boot-prestashop.sh)
set -euo pipefail

: "${SFX:?SFX (namespacing suffix) must be set}"

docker exec -u www-data "ps-$SFX" php -d memory_limit=512M -r '
require "/var/www/html/config/config.inc.php";
Configuration::updateValue("PS_TWO_MERCHANT_API_KEY", "dummy-e2e-key");
Configuration::updateValue("PS_TWO_ENVIRONMENT", "development");
Configuration::updateValue("PS_TWO_MERCHANT_SHORT_NAME", "E2E Test Merchant");
Configuration::updateValue("PS_TWO_MERCHANT_ID", "e2e-merchant-id");
Configuration::updateValue("PS_TWO_API_KEY_VERIFIED", 1);
echo "Two config seeded (hermetic — no live verify_api_key call)\n";
'
docker exec "ps-$SFX" bash -c "rm -rf /var/www/html/var/cache/*"
