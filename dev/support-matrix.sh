#!/usr/bin/env bash
# dev/support-matrix.sh: discover and classify the PrestaShop x PHP
# combinations CI tests, read from upstream on every run (TWO-27173).
#
# Usage:
#   dev/support-matrix.sh            human-readable report
#   dev/support-matrix.sh --php      the PHP lists alone, as one JSON object.
#     Reads only this checkout and php.net, so an outage of GitHub or Docker
#     Hub cannot block the unit, lint and static jobs.
#   dev/support-matrix.sh --all      the PHP lists plus the PrestaShop
#     matrices, as one JSON object for CI.
#
# JSON keys:
#   php        every PHP minor from the plugin's declared floor to the newest
#              php.net supports (the lint job)
#   php_test   the minors the offline suite runs on (from TESTS_PHP_FLOOR)
#   phpstan    the minors PHPStan runs on (from PHPSTAN_PHP_FLOOR)
#   php_newest the newest minor php.net supports (single-leg tool jobs)
#   php_skips  [{job, php, reason}]: a minor a job cannot run on
#   e2e        [{ps, php, image, port, exception, provisional,
#              provisional_reason}]: one leg per runnable combination
#   smoke      the newest patch of each PrestaShop minor in the window on the
#              newest PHP it declares and has an image for
#   skips      [{ps, php, reason}]: combinations in scope that cannot run
#   outside    [{ps, php, reason}]: combinations below the PHP floor a
#              PrestaShop release declares (listed in the summary only)
#
# Support window, and where each part comes from:
#   PHP          php.net/releases (?json): the minors in supported_versions,
#                that is active or security support, not end of life. The
#                plugin's declared floor (README.md, "**PHP**: 7.2+") stays in
#                the window even where php.net has ended its support, and so
#                does every minor between the floor and the newest.
#   PrestaShop   The PrestaShop/PrestaShop GitHub releases (not drafts or
#                pre-releases), windowed by PrestaShop's published release and
#                support cycle,
#                https://www.prestashop-project.org/project-organization/release-cycle/
#                  major version: "bug fixes until the next minor release"
#                  minor version: "bug fixes until the next major release.
#                    Critical bug fixes until the major release following it"
#                  "v8.1 will not receive patch versions anymore" once v8.2
#                    is out
#                So upstream supports the newest minor of the newest major and
#                the newest minor of the major before it (critical fixes until
#                the next major ships). 1.7 counts as a major. PrestaShop
#                publishes no machine-readable form of this policy and
#                endoflife.date does not cover PrestaShop (its API answers
#                404), so the rule above is applied to the release list. Each
#                minor is tested on its newest release.
#   Exceptions   PS_EXCEPTIONS below: releases outside the window a live
#                merchant runs. Each is a named constant with its reason.
#   PHP range    The PHP each release accepts: _PS_INSTALL_MINIMUM_PHP_VERSION_
#                and _PS_INSTALL_MAXIMUM_PHP_VERSION_ in
#                install-dev/install_version.php at the release tag, the range
#                PrestaShop's installer enforces. Its composer.json states only
#                a floor.
#   Images       prestashop/prestashop on Docker Hub (the tags API, not the
#                registry, so it cannot spend the pull allowance the legs
#                need): <release>-<php>-apache, or from 9.0
#                <release>-<build>-classic-<php>-apache, newest build. When the
#                newest release of a minor has no image for a PHP minor yet,
#                the newest older release of that minor that has one is used,
#                with a warning.
#
# Classification of each release in the window x each PHP minor, first match
# wins:
#   outside  below the release's declared PHP floor: not a combination
#            PrestaShop supports, listed in the step summary only
#   run, provisional: above the release's declared PHP ceiling, with an image.
#            New PHP minors are tested from their first GA release
#            (TWO-27173), so these run non-blocking (continue-on-error) with
#            a warning
#   skip     no image to run it on (above the ceiling or not)
#   run      otherwise, blocking
#
# Honest CI (TWO-24998): a transport or parse failure fails discovery, because
# an empty matrix would skip green, as does a matrix with no blocking leg, or a
# named exception whose image is gone.

set -euo pipefail
cd "$(dirname "$0")/.."

mode=report
case "${1:-}" in
    --all) mode=all ;;
    --php) mode=php ;;
    "") ;;
    *) echo "usage: $0 [--all | --php]" >&2; exit 2 ;;
esac

PS_REPO=PrestaShop/PrestaShop
HUB=https://hub.docker.com/v2/namespaces/prestashop/repositories/prestashop/tags
IMAGE_REPO=prestashop/prestashop

# PrestaShop releases tested although upstream no longer supports them:
# "<release> <php minor>  <reason>".
# 1.7.6.5 on PHP 7.2 (TWO-26083): a live merchant runs PrestaShop 1.7.6, which
# is also the module's declared floor (ps_versions_compliancy min 1.7.6.0 in
# twopayment.php), so it stays covered as an explicit exception. PHP 7.2 is
# the module's declared PHP floor and the newest PHP PrestaShop 1.7.6 supports.
PS_EXCEPTIONS=(
    "1.7.6.5 7.2 TWO-26083: a live merchant runs PrestaShop 1.7.6, the module's declared floor"
)

# The offline suite (tests/run.php and the specs it loads) uses PHP 8.1
# syntax, first-class callables in tests/PlacedOrderUpdateSpec.php for one, so
# it cannot parse below 8.1. The lint job still lints every module file on
# every minor from the plugin's PHP floor.
TESTS_PHP_FLOOR=8.1
# PHPStan 2.x (PHPSTAN_VERSION in tests.yml) needs PHP 7.4 or later to run.
PHPSTAN_PHP_FLOOR=7.4

log() { if [ "$mode" = report ]; then echo "$@"; fi; }
die() { echo "::error::support-matrix: $*" >&2; exit 2; }
fetch() {
    curl -fsSL --retry 3 --retry-all-errors --retry-delay 3 --max-time 30 "$@" \
        || die "could not fetch ${*: -1}"
}
# github <path>: the GitHub REST API, with the job's token where there is one
# (the anonymous limit is 60 calls an hour per address).
github() {
    local token=${GH_TOKEN:-${GITHUB_TOKEN:-}}
    if [ -n "$token" ]; then
        fetch -H 'Accept: application/vnd.github+json' -H "Authorization: Bearer $token" "https://api.github.com/$1"
    else
        fetch -H 'Accept: application/vnd.github+json' "https://api.github.com/$1"
    fi
}
# ver_le A B: A <= B in version order.
ver_le() { [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]; }
ver_lt() { [ "$1" != "$2" ] && ver_le "$1" "$2"; }
json_list() { if [ -n "$1" ]; then jq -R . <<<"$1" | jq -sc '.'; else echo '[]'; fi; }
# PrestaShop's major and minor: 1.7.8.11 is major 1.7, minor 1.7.8;
# 9.2.1 is major 9, minor 9.2.
ps_major() { if [[ $1 == 1.* ]]; then cut -d. -f1,2 <<<"$1"; else cut -d. -f1 <<<"$1"; fi; }
ps_minor() { if [[ $1 == 1.* ]]; then cut -d. -f1-3 <<<"$1"; else cut -d. -f1,2 <<<"$1"; fi; }

# --- Declared floors --------------------------------------------------------
php_floor=$(sed -n 's/^- \*\*PHP\*\*: \([0-9][0-9]*\.[0-9][0-9]*\)+.*/\1/p' README.md | head -1)
ps_floor=$(sed -n "s/.*ps_versions_compliancy = array('min' => '\([0-9.]*\)'.*/\1/p" twopayment.php | head -1)
[[ $php_floor =~ ^[0-9]+\.[0-9]+$ ]] && [[ $ps_floor =~ ^[0-9]+(\.[0-9]+)+$ ]] \
    || die "could not read the declared floors (README.md PHP '$php_floor', twopayment.php ps_versions_compliancy min '$ps_floor')"
log "Declared floors: PrestaShop $ps_floor, PHP $php_floor"

# --- PHP --------------------------------------------------------------------
php_json=$(fetch 'https://www.php.net/releases/?json') || exit 2
php_supported=$(jq -r '[.[].supported_versions[]?] | unique | .[]' <<<"$php_json" | sort -V) \
    || die "php.net releases feed did not parse"
[ -n "$php_supported" ] || die "php.net lists no supported PHP minors"
php_newest=$(tail -1 <<<"$php_supported")
# Each major's latest release names its last minor, so every minor from the
# floor to the newest supported one can be listed without a hand-kept range.
php_all=$(jq -r '.[].version' <<<"$php_json" \
    | grep -E '^[0-9]+\.[0-9]+\.' \
    | awk -F. '{ for (i = 0; i <= $2; i++) print $1 "." i }' \
    | sort -V \
    | while read -r m; do
        if ver_le "$php_floor" "$m" && ver_le "$m" "$php_newest"; then echo "$m"; fi
    done)
[ -n "$php_all" ] || die "no PHP minors between the floor $php_floor and $php_newest"
# The declared floor must head the list: a change to php.net's listing must
# not silently drop the oldest minors the module still declares.
[ "$(head -1 <<<"$php_all")" = "$php_floor" ] \
    || die "php.net's listing no longer yields the declared PHP floor $php_floor (got $(tr '\n' ' ' <<<"$php_all"))"
php_test=$(while read -r m; do if ver_le "$TESTS_PHP_FLOOR" "$m"; then echo "$m"; fi; done <<<"$php_all")
php_stan=$(while read -r m; do if ver_le "$PHPSTAN_PHP_FLOOR" "$m"; then echo "$m"; fi; done <<<"$php_all")
php_skips=$(while read -r m; do
    if ver_lt "$m" "$TESTS_PHP_FLOOR"; then
        jq -nc --arg php "$m" --arg f "$TESTS_PHP_FLOOR" \
            '{job: "Offline suite", php: $php, reason: "the offline suite uses PHP \($f) syntax; the lint job lints the module on PHP \($php)"}'
    fi
    if ver_lt "$m" "$PHPSTAN_PHP_FLOOR"; then
        jq -nc --arg php "$m" --arg f "$PHPSTAN_PHP_FLOOR" \
            '{job: "PHPStan", php: $php, reason: "PHPStan 2.x needs PHP \($f) or later to run"}'
    fi
done <<<"$php_all" | jq -sc '.')
log "PHP supported (php.net): $(tr '\n' ' ' <<<"$php_supported")"
log "PHP lint: $(tr '\n' ' ' <<<"$php_all")"
log "PHP offline suite: $(tr '\n' ' ' <<<"$php_test")"
log "PHP PHPStan: $(tr '\n' ' ' <<<"$php_stan")"

php_obj=$(jq -nc --argjson php "$(json_list "$php_all")" --argjson php_test "$(json_list "$php_test")" \
    --argjson phpstan "$(json_list "$php_stan")" --arg php_newest "$php_newest" --argjson php_skips "$php_skips" \
    '{php: $php, php_test: $php_test, phpstan: $phpstan, php_newest: $php_newest, php_skips: $php_skips}')
if [ "$mode" = php ]; then
    echo "$php_obj"
    exit 0
fi

# --- PrestaShop window ------------------------------------------------------
releases=$(github "repos/$PS_REPO/releases?per_page=100") || exit 2
ps_releases=$(jq -r '.[] | select((.draft | not) and (.prerelease | not)) | .tag_name
        | select(test("^[0-9]+(\\.[0-9]+){2,3}$"))' <<<"$releases" | sort -V) \
    || die "the GitHub releases of $PS_REPO did not parse"
[ -n "$ps_releases" ] || die "$PS_REPO lists no releases"
majors=$(while read -r r; do ps_major "$r"; done <<<"$ps_releases" | sort -V -u)
[ "$(wc -l <<<"$majors")" -ge 2 ] || die "fewer than two PrestaShop majors among the releases: $(tr '\n' ' ' <<<"$majors")"
window=()
for major in $(tail -2 <<<"$majors"); do
    newest=$(while read -r r; do if [ "$(ps_major "$r")" = "$major" ]; then echo "$r"; fi; done <<<"$ps_releases" | tail -1)
    window+=("$(ps_minor "$newest")")
done
log "PrestaShop window (newest minor of the two newest majors): ${window[*]}"

# releases_of <minor>: every release of a minor, newest first.
releases_of() { while read -r r; do if [ "$(ps_minor "$r")" = "$1" ]; then echo "$r"; fi; done <<<"$ps_releases" | sort -V -r; }

# hub_tags <name filter>: every prestashop/prestashop tag whose name contains it.
hub_tags() {
    local url="$HUB?page_size=100&name=$1" page
    while [ -n "$url" ]; do
        page=$(fetch "$url") || exit 2
        jq -r '.results[].name' <<<"$page" || die "Docker Hub tags for '$1' did not parse"
        url=$(jq -r '.next // empty' <<<"$page")
    done
}

# image_tag <release> <php minor> <tag list>: the tag to run, or nothing.
image_tag() {
    local r=$1 php=$2 tags=$3 plain classic
    plain="$r-$php-apache"
    if grep -qxF "$plain" <<<"$tags"; then echo "$plain"; return; fi
    classic=$(grep -E "^${r//./\\.}-[0-9]+\.[0-9]+-classic-${php//./\\.}-apache\$" <<<"$tags" \
        | sed -E "s/^${r//./\\.}-([0-9.]+)-classic-.*/\1 &/" | sort -V | tail -1 | cut -d' ' -f2)
    if [ -n "$classic" ]; then echo "$classic"; fi
}

# php_range <release>: "<min minor> <max minor or ->" from install_version.php,
# read from its _PS_INSTALL_MINIMUM_PHP_VERSION_ and _MAXIMUM_ strings ('7.2.5',
# '8.1'). Not the _ID_ constants: 8.2 declares its ceiling as 81099.
php_range() {
    local src min max
    src=$(fetch "https://raw.githubusercontent.com/$PS_REPO/$1/install-dev/install_version.php") || exit 2
    min=$(sed -n "s/.*_PS_INSTALL_MINIMUM_PHP_VERSION_', *'\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p" <<<"$src")
    max=$(sed -n "s/.*_PS_INSTALL_MAXIMUM_PHP_VERSION_', *'\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p" <<<"$src")
    [[ $min =~ ^[0-9]+\.[0-9]+$ ]] || die "PrestaShop $1 install_version.php declares no minimum PHP"
    echo "$min ${max:--}"
}

entries=()
port=18100
add() { # add <class> <ps> <php> <image> <reason> [exception]
    port=$((port + 1))
    entries+=("$(jq -nc --arg class "$1" --arg ps "$2" --arg php "$3" --arg image "${4:+$IMAGE_REPO:$4}" \
        --arg reason "$5" --arg port "$port" --arg exception "${6:-}" \
        '{class: $class, ps: $ps, php: $php, image: $image, reason: $reason, port: $port,
          exception: ($exception != ""), provisional: ($class == "provisional"),
          provisional_reason: (if $class == "provisional" then $reason else "" end)}')")
}

for minor in "${window[@]}"; do
    rels=$(releases_of "$minor")
    newest=$(head -1 <<<"$rels")
    range=$(php_range "$newest")
    read -r ps_min ps_max <<<"$range"
    tags=$(hub_tags "$minor.")
    log "  PrestaShop $minor: newest release $newest, declares PHP $ps_min to ${ps_max/-/(no ceiling)}"
    for php in $php_all; do
        if ver_lt "$php" "$ps_min"; then
            add outside "$newest" "$php" "" "PrestaShop $newest requires PHP $ps_min or later (install_version.php)"
            continue
        fi
        tag='' used=''
        for r in $rels; do
            tag=$(image_tag "$r" "$php" "$tags")
            if [ -n "$tag" ]; then used=$r; break; fi
        done
        above=''
        if [ "$ps_max" != - ] && ver_lt "$ps_max" "$php"; then above=1; fi
        if [ -z "$tag" ]; then
            if [ -n "$above" ]; then
                add skip "$newest" "$php" "" "PrestaShop $newest declares PHP up to $ps_max (install_version.php) and $IMAGE_REPO publishes no PrestaShop $minor image on PHP $php"
            else
                add skip "$newest" "$php" "" "$IMAGE_REPO publishes no PrestaShop $minor image on PHP $php"
            fi
            continue
        fi
        if [ "$used" != "$newest" ]; then
            echo "::warning title=PrestaShop image behind::$IMAGE_REPO has no image of PrestaShop $newest on PHP $php yet; testing $used ($tag) instead" >&2
        fi
        if [ -n "$above" ]; then
            add provisional "$used" "$php" "$tag" "PrestaShop $newest declares PHP up to $ps_max (install_version.php); PHP $php runs from its first GA release, non-blocking"
        else
            add run "$used" "$php" "$tag" ""
        fi
    done
done

for line in "${PS_EXCEPTIONS[@]}"; do
    read -r rel php reason <<<"$line"
    [ "$(ps_minor "$rel")" = "$(ps_minor "$ps_floor")" ] \
        || die "exception PrestaShop $rel is not on the module's declared floor $ps_floor; review PS_EXCEPTIONS"
    tags=$(hub_tags "$rel-")
    tag=$(image_tag "$rel" "$php" "$tags")
    [ -n "$tag" ] || die "exception PrestaShop $rel on PHP $php has no $IMAGE_REPO image"
    log "  PrestaShop $rel on PHP $php: named exception ($reason)"
    add run "$rel" "$php" "$tag" "" "$reason"
done

all=$(printf '%s\n' "${entries[@]}" | jq -sc '.')
e2e=$(jq -c '[.[] | select(.class == "run" or .class == "provisional") | del(.class, .reason)]' <<<"$all")
skips=$(jq -c '[.[] | select(.class == "skip") | {ps, php, reason}]' <<<"$all")
outside=$(jq -c '[.[] | select(.class == "outside") | {ps, php, reason}]' <<<"$all")
# Smoke: per PrestaShop minor in the window, its newest blocking combination.
smoke=$(jq -c '
    [.[] | select(.class == "run" and (.exception | not))]
    | group_by(.ps | split(".") | .[0:2] | join("."))
    | map(sort_by(.php | split(".") | map(tonumber)) | last | del(.class, .reason))' <<<"$all")
runs=$(jq length <<<"$e2e")
blocking=$(jq '[.[] | select(.provisional | not)] | length' <<<"$e2e")
[ "$blocking" -gt 0 ] || die "no blocking e2e combination ($runs runnable), so e2e could never fail"
[ "$(jq length <<<"$smoke")" -eq "${#window[@]}" ] \
    || die "a PrestaShop minor in the window (${window[*]}) has no blocking combination for the smoke legs"

if [ "$mode" = all ]; then
    jq -c --argjson e2e "$e2e" --argjson smoke "$smoke" --argjson skips "$skips" --argjson outside "$outside" \
        '. + {e2e: $e2e, smoke: $smoke, skips: $skips, outside: $outside}' <<<"$php_obj"
    exit 0
fi

echo
echo "E2E and integration ($runs run, $((runs - blocking)) of them non-blocking; $(jq length <<<"$skips") skipped):"
jq -r '.[] | "PrestaShop \(.ps)  PHP \(.php)" as $c
    | if .class == "skip" then "  SKIP  \($c): \(.reason)"
      elif .class == "outside" then "  n/a   \($c): \(.reason)"
      elif .class == "provisional" then "  RUN*  \($c)  \(.image): \(.reason)"
      else "  RUN   \($c)  \(.image)" end' <<<"$all"
echo
echo "Smoke and carrier-less:"
jq -r '.[] | "  PrestaShop \(.ps)  PHP \(.php)  \(.image)"' <<<"$smoke"
