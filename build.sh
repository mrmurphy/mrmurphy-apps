#!/bin/bash

# Build a versioned plugin zip for wp-admin upload.
# Output: dist/mrmurphy-apps-{version}.zip

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="mrmurphy-apps"
MAIN_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.php"
DIST_DIR="${PLUGIN_DIR}/dist"

get_version() {
	if [[ ! -f "$MAIN_FILE" ]]; then
		echo "1.0.0"
		return
	fi

	grep -E "^\s*\*\s*Version:" "$MAIN_FILE" \
		| sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/' \
		| head -1
}

bump_version() {
	local current="$1"
	local major minor patch

	major=$(echo "$current" | cut -d. -f1)
	minor=$(echo "$current" | cut -d. -f2)
	patch=$(echo "$current" | cut -d. -f3)
	patch=$((patch + 1))

	echo "${major}.${minor}.${patch}"
}

update_version() {
	local new_version="$1"

	sed -i '' -E "s/^(\s*\*\s*Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\1${new_version}/" "$MAIN_FILE"
	sed -i '' -E "s/define\([[:space:]]*'MRMURPHY_APPS_VERSION',[[:space:]]*'[0-9]+\.[0-9]+\.[0-9]+'\);/define( 'MRMURPHY_APPS_VERSION', '${new_version}' );/" "$MAIN_FILE"
	sed -i '' -E "s/^Stable tag:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+/Stable tag: ${new_version}/" "${PLUGIN_DIR}/readme.txt"
}

VERSION="$(get_version)"

if [[ "${1:-}" == "--bump" ]]; then
	VERSION="$(bump_version "$VERSION")"
	echo "Bumping version to ${VERSION}..."
	update_version "$VERSION"
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"
STAGING_DIR="$(mktemp -d)"

cleanup() {
	rm -rf "$STAGING_DIR"
}
trap cleanup EXIT

mkdir -p "$DIST_DIR"
rm -f "${DIST_DIR}/${PLUGIN_SLUG}-"*.zip

echo "Building ${ZIP_NAME}..."

rsync -a \
	--exclude '.git' \
	--exclude '.gitignore' \
	--exclude '.DS_Store' \
	--exclude '.cursor' \
	--exclude '.claude' \
	--exclude '*.log' \
	--exclude 'dist' \
	--exclude 'build.sh' \
	"${PLUGIN_DIR}/" "${STAGING_DIR}/${PLUGIN_SLUG}/"

(
	cd "$STAGING_DIR"
	zip -r "$ZIP_PATH" "$PLUGIN_SLUG" > /dev/null
)

echo "Done: ${ZIP_PATH}"
echo "Install via wp-admin: Plugins -> Add New -> Upload Plugin"
