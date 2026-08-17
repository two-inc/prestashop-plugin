#!/usr/bin/env bash
#
# Prints the API / merchant-portal / checkout-page hosts the RUNNING
# container is actually configured to hit, by execing dev/probe-hosts.php
# inside the container and reformatting three of its lines.
#
# Runs inside the container (not on the host) because _PS_MODE_DEV_ and the
# TWO_*_BASE_URL overrides are read from the container's own process
# environment, baked in at container creation - see "Service URL overrides"
# in README.md. dev/probe-hosts.php is the one place that resolution logic
# lives (Twopayment::getTwoCheckoutHostUrl/getTwoPortalUrl,
# TwoSoleTrader::getSignupPageUrl); this script only parses its output, it
# does not re-derive the override-vs-default logic.
#
# Usage: dev/print-resolved-hosts.sh <container-name> <module-name>
# Prints nothing (and exits 0) if the container isn't reachable - callers
# use this for a "nice to have" status block, not a hard dependency.
set -euo pipefail

CONTAINER="$1"
MODULE_NAME="$2"

DUMP=$(docker exec "$CONTAINER" php "/var/www/html/modules/$MODULE_NAME/dev/probe-hosts.php" 2>/dev/null) || exit 0

API=$(sed -n 's/^getTwoCheckoutHostUrl(): //p' <<< "$DUMP")
PORTAL=$(sed -n 's/^getTwoPortalUrl(): //p' <<< "$DUMP")
CHECKOUT=$(sed -n 's/^TwoSoleTrader::getSignupPageUrl(): //p' <<< "$DUMP")

[ -n "$API" ] && echo " API:               $API"
[ -n "$PORTAL" ] && echo " Portal:            $PORTAL"
[ -n "$CHECKOUT" ] && echo " Checkout (signup): $CHECKOUT"

exit 0
