#!/bin/bash

# Two Payment Module - Release Packaging Script
# Creates a clean ZIP file for distribution

set -e

MODULE_NAME="twopayment"
MODULE_DIR="$(cd "$(dirname "$0")" && pwd)"
PARENT_DIR="$(dirname "${MODULE_DIR}")"
TEMP_DIR="/tmp/${MODULE_NAME}-package-$$"

# Check we're in the right directory
if [ ! -f "${MODULE_DIR}/twopayment.php" ]; then
    echo "ERROR: twopayment.php not found. Are you in the correct directory?"
    exit 1
fi

# Derive module version directly from twopayment.php to avoid manual mismatch
VERSION=$(grep -E '\$this->version[[:space:]]*=' "${MODULE_DIR}/twopayment.php" | head -1 | sed -E "s/.*=[[:space:]]*['\"]([^'\"]+)['\"].*/\1/")
if [ -z "${VERSION}" ]; then
    echo "ERROR: Unable to derive module version from twopayment.php"
    exit 1
fi
PACKAGE_NAME="${MODULE_NAME}-v${VERSION}.zip"

echo "=========================================="
echo "Two Payment Module - Release Packaging"
echo "Version: ${VERSION}"
echo "=========================================="
echo ""

# Verify config.xml is aligned with derived module version
XML_VERSION=$(grep -E "<version><!\[CDATA\[${VERSION}\]\]></version>" "${MODULE_DIR}/config.xml" | head -1)

if [ -z "$XML_VERSION" ]; then
    echo "WARNING: Version mismatch detected!"
    echo "Please verify version is ${VERSION} in config.xml"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo "✓ Version check passed"
echo ""

# Create temporary directory
echo "Creating temporary package directory..."
mkdir -p "${TEMP_DIR}/${MODULE_NAME}"
cd "${MODULE_DIR}"

# Stamp the deployed commit into a sidecar file INSIDE the module source dir so
# it is picked up by the copy below and ships inside the packaged module.
# getTwoDeployedCommitHash() reads this LAST (TWO-25194) — a live `.git` gitlink or
# directory always wins, because this stamp is frozen at build time. The stamp is what
# resolves the sha in a packaged artifact, which ships no .git at all.
SIDECAR_FILE="${MODULE_DIR}/.two-deployed-commit"
SIDECAR_PREEXISTING=0
if [ -f "${SIDECAR_FILE}" ]; then
    SIDECAR_PREEXISTING=1
fi
if COMMIT_SHA=$(git -C "${MODULE_DIR}" rev-parse --short HEAD 2>/dev/null) && [ -n "${COMMIT_SHA}" ]; then
    printf '%s\n' "${COMMIT_SHA}" > "${SIDECAR_FILE}"
    echo "✓ Stamped deployed commit: ${COMMIT_SHA}"
else
    echo "ERROR: Unable to resolve git commit for .two-deployed-commit stamp"
    exit 1
fi

# Remove the stamp again on exit unless it was already tracked in the source tree,
# so packaging never leaves the working tree dirty.
cleanup_sidecar() {
    if [ "${SIDECAR_PREEXISTING}" -eq 0 ]; then
        rm -f "${SIDECAR_FILE}"
    fi
    rm -rf "${TEMP_DIR}"
}
trap cleanup_sidecar EXIT

# Copy files, excluding unnecessary ones
echo "Copying module files (excluding dev files)..."

# Use rsync if available, otherwise use find + cp
if command -v rsync &> /dev/null; then
    rsync -av \
        --exclude='.git' \
        --exclude='.gitignore' \
        --exclude='.DS_Store' \
        --exclude='__MACOSX' \
        --exclude='.cursor' \
        --exclude='.ai' \
        --exclude='.review' \
        --exclude='.phpunit.cache' \
        --exclude='CLAUDE.md' \
        --exclude='PRODUCTION_REVIEW.md' \
        --exclude='package-release.sh' \
        --exclude='*.log' \
        --exclude='node_modules' \
        --exclude='.idea' \
        --exclude='*.swp' \
        --exclude='*.swo' \
        --exclude='*~' \
        --exclude='.env' \
        --exclude='.env.local' \
        --exclude='composer.lock' \
        --exclude='package.json' \
        --exclude='package-lock.json' \
        ./ "${TEMP_DIR}/${MODULE_NAME}/"
else
    # Fallback: use find and cp
    find . -type f \
        ! -path './.git/*' \
        ! -path './.cursor/*' \
        ! -path './.ai/*' \
        ! -path './.review/*' \
        ! -path './.phpunit.cache/*' \
        ! -name '.gitignore' \
        ! -name '.DS_Store' \
        ! -name 'CLAUDE.md' \
        ! -name 'PRODUCTION_REVIEW.md' \
        ! -name 'package-release.sh' \
        ! -name '*.log' \
        ! -path '*/node_modules/*' \
        ! -path '*/.idea/*' \
        ! -name '*.swp' \
        ! -name '*.swo' \
        ! -name '*~' \
        ! -name '.env*' \
        ! -name 'composer.lock' \
        ! -name 'package.json' \
        ! -name 'package-lock.json' \
        -exec cp --parents {} "${TEMP_DIR}/${MODULE_NAME}/" \;
fi

echo "✓ Files copied"
echo ""

# Remove any .DS_Store files that might have been copied
find "${TEMP_DIR}" -name ".DS_Store" -delete 2>/dev/null || true

# Fail loudly if the commit stamp did not make it into the built artifact -
# a silently missing sidecar means the shop reports no commit hash at all.
if [ ! -s "${TEMP_DIR}/${MODULE_NAME}/.two-deployed-commit" ]; then
    echo "ERROR: .two-deployed-commit missing (or empty) in the packaged module."
    echo "       Check the copy exclude-lists in this script."
    exit 1
fi
echo "✓ Commit stamp present in artifact: $(cat "${TEMP_DIR}/${MODULE_NAME}/.two-deployed-commit")"

# Create ZIP file
echo "Creating ZIP archive..."
cd "${TEMP_DIR}"
zip -r "${PARENT_DIR}/${PACKAGE_NAME}" "${MODULE_NAME}" -q
cd - > /dev/null

# Clean up temp directory
rm -rf "${TEMP_DIR}"

# Get file size
FILE_SIZE=$(du -h "${PARENT_DIR}/${PACKAGE_NAME}" | cut -f1)

echo "✓ Package created successfully!"
echo ""
echo "=========================================="
echo "Package Details:"
echo "=========================================="
echo "Filename: ${PACKAGE_NAME}"
echo "Location: ${PARENT_DIR}/${PACKAGE_NAME}"
echo "Size: ${FILE_SIZE}"
echo ""
echo "Package contents verified:"
echo "  ✓ Module files"
echo "  ✓ Controllers"
echo "  ✓ Views (CSS, JS, templates)"
echo "  ✓ Translations"
echo "  ✓ Upgrade scripts"
echo "  ✓ Vendor dependencies"
echo "  ✓ README.md"
echo "  ✓ CHANGELOG.md"
echo ""
echo "Excluded from package:"
echo "  ✗ .git directory"
echo "  ✗ .cursor directory (IDE config)"
echo "  ✗ .ai directory (AI context files)"
echo "  ✗ CLAUDE.md (AI context file)"
echo "  ✗ PRODUCTION_REVIEW.md (internal docs)"
echo "  ✗ Development files (.DS_Store, logs, etc.)"
echo ""
echo "Ready for distribution! 🚀"
echo "=========================================="
