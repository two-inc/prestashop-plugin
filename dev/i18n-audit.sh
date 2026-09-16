#!/usr/bin/env bash
#
# Translation catalogue audit (TWO-25793). Sibling of the audit the other
# plugin repos run, and the one-command local entry point for the checks in
# tests/TranslationCatalogueSpec.php: orphan rows, missing keys, rows the
# runtime's !empty() reads as absent, sprintf token drift, theme-scoped rows
# that shadow the shared catalogue, and catalogue files nothing gates.
#
# A translation catalogue fails in exactly one way — the key the runtime asks
# for is not the key in the file — and it fails without an error, a warning or
# a log line. The shop simply renders English.
#
# Usage: dev/i18n-audit.sh   (run from anywhere)

set -euo pipefail

cd "$(dirname "$0")/.."

command -v php >/dev/null 2>&1 || {
    echo "::error::php not found"
    exit 4
}

# The spec reports by throwing; without the catch, PHP's uncaught-exception
# handler buries the message under a stack trace.
php -r '
require "tests/TranslationCatalogueSpec.php";
try {
    TranslationCatalogueSpec::runAll();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
echo "i18n audit OK: every gated catalogue is in step with the module source." . PHP_EOL;
' || {
    echo "::error::i18n audit failed"
    exit 1
}
