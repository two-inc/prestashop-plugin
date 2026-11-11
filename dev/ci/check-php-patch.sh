#!/usr/bin/env bash
# dev/ci/check-php-patch.sh <image> <running-php-version> <expected-minor>
#
# Two checks on the PHP inside an e2e or integration shop (TWO-27173).
#
# Identity: the running PHP must be the minor the leg is named for. An image
# that runs another minor is the wrong leg, not a stale one, so that fails.
#
# Patch age: the newest patch of that minor and its release date come from
# php.net (https://www.php.net/releases/?json&version=<minor>), which for an
# end-of-life minor such as 7.2 is that minor's final patch. An image behind it
# gets a ::warning:: naming the image, the running patch and the newest one,
# however long it has lagged. It never fails the leg: a php.net lookup that
# fails or finds no release is a warning too.
set -euo pipefail

usage="usage: $0 <image> <running-php-version> <expected-minor>"
image=${1:?$usage}
running=${2:?$usage}
expected=${3:?$usage}
[[ $running =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] || { echo "::error::php-patch: '$running' is not a PHP version" >&2; exit 1; }
minor=$(cut -d. -f1,2 <<<"$running")
if [ "$minor" != "$expected" ]; then
    echo "::error title=Wrong PHP minor::$image runs PHP $running, but this leg is PHP $expected"
    exit 1
fi

release=$(curl -fsSL --retry 3 --retry-all-errors --retry-delay 3 --max-time 30 \
    "https://www.php.net/releases/?json&version=$minor") \
    || { echo "::warning title=PHP patch not checked::$image runs PHP $running; could not query php.net for the newest $minor patch"; exit 0; }
latest=$(jq -r '.version // empty' <<<"$release" 2>/dev/null) || latest=
released=$(jq -r '.date // empty' <<<"$release" 2>/dev/null) || released=
[ -n "$latest" ] && [ -n "$released" ] \
    || { echo "::warning title=PHP patch not checked::$image runs PHP $running; php.net lists no release for PHP $minor"; exit 0; }

newest=$(printf '%s\n%s\n' "$running" "$latest" | sort -V | tail -1)
if [ "$newest" = "$running" ]; then
    echo "$image runs PHP $running, the newest $minor patch (php.net: $latest, released $released)"
    exit 0
fi
echo "::warning title=PHP patch behind::$image runs PHP $running; php.net's newest $minor patch is $latest, released $released"
