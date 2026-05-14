#!/bin/bash

# Usage: ./start-proxy.sh [--background|stop|url] [FRP_AUTH_TOKEN]
#
# If no token is provided, attempts to fetch it from GCP Secret Manager
# (requires an active @two.inc gcloud login).

if [ -f .env.local ]; then
  set -a
  source .env.local
  set +a
fi

PROXY_USER="${PROXY_USER:-$USER}"
export HOST="${HOST:-127.0.0.1}"
export PORT="${PORT:-1235}"
PIDFILE=".frpc.pid"

USER_LOWER=$(echo "${PROXY_USER}" | tr '[:upper:]' '[:lower:]')
SANITIZED_USER=$(echo "${USER_LOWER}" | sed -E 's/[^a-z0-9-]+/-/g' | sed -E 's/^-+|-+$//g' | sed -E 's/--+/-/g')
export SUBDOMAIN="prestashop-${SANITIZED_USER}"

PROXY_URL="https://${SUBDOMAIN}.frp.beta.two.inc"

# ── stop mode ────────────────────────────────────────────────────────────────
if [ "$1" = "stop" ]; then
  if [ -f "$PIDFILE" ]; then
    kill "$(cat "$PIDFILE")" 2>/dev/null
    rm -f "$PIDFILE"
    echo "Proxy stopped"
  fi
  exit 0
fi

# ── url mode ─────────────────────────────────────────────────────────────────
if [ "$1" = "url" ]; then
  if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
    echo "$PROXY_URL"
  fi
  exit 0
fi

# ── start mode ───────────────────────────────────────────────────────────────
MODE=""
TOKEN_ARG=""
for arg in "$@"; do
  if [ "$arg" = "--background" ]; then
    MODE="background"
  else
    TOKEN_ARG="$arg"
  fi
done

if [ -f "$PIDFILE" ]; then
  kill "$(cat "$PIDFILE")" 2>/dev/null
  rm -f "$PIDFILE"
fi

if [ -n "$TOKEN_ARG" ]; then
  export FRP_AUTH_TOKEN="$TOKEN_ARG"
elif [ -n "$FRP_AUTH_TOKEN" ]; then
  export FRP_AUTH_TOKEN
else
  echo "Fetching FRP_AUTH_TOKEN from Secret Manager..."
  if ! FRP_AUTH_TOKEN=$(gcloud secrets versions access latest --secret="FRP_AUTH_TOKEN" --project="two-beta" 2>&1); then
    echo "Failed to fetch FRP_AUTH_TOKEN:"
    echo "$FRP_AUTH_TOKEN"
    echo ""
    echo "Usage: ./start-proxy.sh [--background] <FRP_AUTH_TOKEN>"
    echo "   or: export FRP_AUTH_TOKEN=<token> before running"
    exit 1
  fi
  export FRP_AUTH_TOKEN
fi

frpc -c frpc.toml &
FRP_PID=$!

sleep 2

if ! ps -p $FRP_PID >/dev/null 2>&1; then
  echo "frpc failed to start"
  exit 1
fi

echo "$FRP_PID" > "$PIDFILE"

echo ""
echo "Proxy: $PROXY_URL"
echo ""

if [ "$MODE" = "background" ]; then
  disown $FRP_PID
  exit 0
fi

trap 'kill $FRP_PID 2>/dev/null; rm -f "$PIDFILE"' EXIT
wait $FRP_PID
