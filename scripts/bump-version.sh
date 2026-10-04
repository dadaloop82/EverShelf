#!/usr/bin/env bash
#
# bump-version.sh — update the app version everywhere it is duplicated.
#
# Usage:
#   scripts/bump-version.sh 1.9.0 [ASSET_STAMP]
#
#   ASSET_STAMP (optional) overrides the cache-busting token used for the
#   ?v= query strings and the i18n loader. Defaults to "<YYYYMMDD>a".
#
# Touches:
#   index.html   – header badge, preloader badge, every ?v= query
#   manifest.json – "version"
#   sw.js        – CACHE name
#   assets/js/app.js – _I18N_VERSION (translation cache buster)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

VERSION="${1:-}"
STAMP="${2:-$(date +%Y%m%d)a}"

if [[ -z "$VERSION" ]]; then
    echo "Usage: $0 <major.minor.patch> [asset-stamp]" >&2
    exit 1
fi
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: version must look like 1.2.3 (got '$VERSION')" >&2
    exit 1
fi
if ! [[ "$STAMP" =~ ^[0-9]{8}[a-z]$ ]]; then
    echo "Error: asset stamp must look like 20261004a (got '$STAMP')" >&2
    exit 1
fi

INDEX="$ROOT/index.html"
MANIFEST="$ROOT/manifest.json"
SW="$ROOT/sw.js"
APPJS="$ROOT/assets/js/app.js"

for f in "$INDEX" "$MANIFEST" "$SW" "$APPJS"; do
    [[ -f "$f" ]] || { echo "Error: missing $f" >&2; exit 1; }
done

# ── index.html: badges + cache-busting query strings ────────────────────────
sed -i -E "s/(header-version\">v)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" "$INDEX"
sed -i -E "s/(preloader-version\">v)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" "$INDEX"
sed -i -E "s/(\?v=)[0-9]{8}[a-z]/\1${STAMP}/g" "$INDEX"

# ── manifest.json: version field ────────────────────────────────────────────
sed -i -E "s/(\"version\"[[:space:]]*:[[:space:]]*\")[0-9]+\.[0-9]+\.[0-9]+(\")/\1${VERSION}\2/" "$MANIFEST"

# ── sw.js: cache name ───────────────────────────────────────────────────────
sed -i -E "s/(const CACHE = 'evershelf-v)[0-9.]+(')/\1${VERSION}\2/" "$SW"

# ── app.js: i18n cache buster + asset query strings ─────────────────────────
sed -i -E "s/(_I18N_VERSION = ')[0-9]{8}[a-z](')/\1${STAMP}\2/" "$APPJS"
sed -i -E "s/(\?v=)[0-9]{8}[a-z]/\1${STAMP}/g" "$APPJS"

echo "Bumped version to v${VERSION} (asset stamp ${STAMP}) in:"
echo "  - index.html (2 badges + ?v= queries)"
echo "  - manifest.json"
echo "  - sw.js (CACHE)"
echo "  - assets/js/app.js (_I18N_VERSION)"
echo
echo "Remember to add a CHANGELOG.md entry for v${VERSION}."
