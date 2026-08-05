#!/usr/bin/env bash
# Shared PrestaShop CI harness (TWO-25326): start a throwaway in-container
# stand-in for Two's API on 127.0.0.1:$TWO_API_STUB_PORT, answering ONLY
# /v1/merchant/verify_api_key.
#
# Needed because the module now withholds the payment option and the
# company-search control whenever the stored API key cannot be verified -
# unreachable API included, which is what this harness deliberately arranges
# by pointing TWO_API_BASE_URL at a port nothing listens on. A UI suite about
# the payment tile therefore needs the verification call to succeed, and it
# must not succeed against Two's real sandbox: this repo is public and its CI
# holds no merchant key. See dev/ci/two-api-stub.php for what is (and is not)
# stubbed.
#
# The caller is responsible for pointing the module at it: set
# TWO_API_BASE_URL="http://127.0.0.1:$TWO_API_STUB_PORT" for
# boot-prestashop.sh (the module only honours that override in PS dev mode).
#
# Usage: start-two-api-stub.sh
# Required env: SFX (same namespacing suffix passed to boot-prestashop.sh)
# Optional env: TWO_API_STUB_PORT (default 8099)
set -euo pipefail

: "${SFX:?SFX (namespacing suffix) must be set}"
PORT="${TWO_API_STUB_PORT:-8099}"

docker cp "$(dirname "$0")/two-api-stub.php" "ps-$SFX:/tmp/two-api-stub.php"

# Detached, and bound to loopback INSIDE the container: the only client is the
# module's own cURL call from that same container, so the stub is never exposed
# on the host or the docker network.
docker exec -d "ps-$SFX" php -S "127.0.0.1:$PORT" /tmp/two-api-stub.php

# Fail loud rather than leaving the e2e suite to report "payment option never
# rendered" for a reason that has nothing to do with the module.
for _ in $(seq 1 20); do
  if docker exec "ps-$SFX" bash -c \
    "curl -fsS -o /dev/null 'http://127.0.0.1:$PORT/v1/merchant/verify_api_key'"; then
    echo "two-api stub answering on 127.0.0.1:$PORT"
    exit 0
  fi
  sleep 1
done

echo "::error::two-api stub never came up on 127.0.0.1:$PORT"
exit 1
