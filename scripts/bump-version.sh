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
#   README.md    – version badge (the "What's new" line below it stays manual)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

VERSION="${1:-}"
STAMP="${2:-}"

if [[ -z "$VERSION" ]]; then
    echo "Usage: $0 <major.minor.patch> [asset-stamp]" >&2
    exit 1
fi
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: version must look like 1.2.3 (got '$VERSION')" >&2
    exit 1
fi

INDEX="$ROOT/index.html"
MANIFEST="$ROOT/manifest.json"
SW="$ROOT/sw.js"
APPJS="$ROOT/assets/js/app.js"
README="$ROOT/README.md"

for f in "$INDEX" "$MANIFEST" "$SW" "$APPJS" "$README"; do
    [[ -f "$f" ]] || { echo "Error: missing $f" >&2; exit 1; }
done

# ── Asset stamp ─────────────────────────────────────────────────────────────
# Two bumps in the same day used to reuse "<today>a" verbatim, so a CSS-only
# change kept the same ?v= and the browser served the stale file. Default is now
# "next letter after the stamp already in index.html" (20261014a → 20261014b).
# A busy day can run out of single letters: after `z` the suffix grows instead of
# refusing to bump (`z` → `za` → `zb` … `zz` → `zza`).
next_stamp_suffix() {
    local suffix="$1"
    if [[ "$suffix" =~ ^z*$ ]]; then
        printf '%sa' "$suffix"
        return
    fi
    local head="${suffix%?}" last="${suffix: -1}" next
    next="$(printf "\\$(printf '%03o' $(( $(printf '%d' "'${last}") + 1 )))")"
    printf '%s%s' "$head" "$next"
}

if [[ -z "$STAMP" ]]; then
    TODAY="$(date +%Y%m%d)"
    CURRENT_STAMP="$(grep -oE '\?v=[0-9]{8}[a-z]+' "$INDEX" | head -1 | sed 's/?v=//' || true)"
    if [[ "$CURRENT_STAMP" == "$TODAY"* && "$CURRENT_STAMP" =~ ^[0-9]{8}[a-z]+$ ]]; then
        STAMP="${TODAY}$(next_stamp_suffix "${CURRENT_STAMP:8}")"
    else
        STAMP="${TODAY}a"
    fi
fi

if ! [[ "$STAMP" =~ ^[0-9]{8}[a-z]+$ ]]; then
    echo "Error: asset stamp must look like 20261004a (got '$STAMP')" >&2
    exit 1
fi


# The cache name carries the stamp letter too: a style-only release still has to
# invalidate the service-worker cache.
STAMP_LETTER="${STAMP: -1}"

# ── index.html: badges + cache-busting query strings ────────────────────────
sed -i -E "s/(header-version\">v)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" "$INDEX"
sed -i -E "s/(preloader-version\">v)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" "$INDEX"
sed -i -E "s/(\?v=)[0-9]{8}[a-z]+/\1${STAMP}/g" "$INDEX"

# ── manifest.json: version field ────────────────────────────────────────────
sed -i -E "s/(\"version\"[[:space:]]*:[[:space:]]*\")[0-9]+\.[0-9]+\.[0-9]+(\")/\1${VERSION}\2/" "$MANIFEST"

# ── sw.js: cache name (version + stamp letter) ──────────────────────────────
# The stamp can grow past one letter (…20261014z → …20261014za, see next_stamp_suffix),
# so the suffix is matched with `[a-z]*`: `[a-z]?` silently skipped the whole sed and
# left the CACHE on the previous version (stale sw.js pinned clients to old assets).
sed -i -E "s/(const CACHE = 'evershelf-v)[0-9]+\.[0-9]+\.[0-9]+[a-z]*(')/\1${VERSION}${STAMP_LETTER}\2/" "$SW"

# ── app.js: i18n cache buster + asset query strings ─────────────────────────
sed -i -E "s/(_I18N_VERSION = ')[0-9]{8}[a-z]+(')/\1${STAMP}\2/" "$APPJS"
sed -i -E "s/(\?v=)[0-9]{8}[a-z]+/\1${STAMP}/g" "$APPJS"

# ── README.md: shields.io version badge (drifted to 1.11.6 once already) ────
sed -i -E "s|(badge/version-)[0-9]+\.[0-9]+\.[0-9]+|\1${VERSION}|" "$README"

# ── the branch label beside every version ──────────────────────────────────
# The semver alone does not say which line of work a build came from, and a
# hand-kept label goes stale within one release. The name comes from git, so a
# bump on develop says `develop`, a bump on a refactor branch says its name, and a
# release built from main says `main`. Nothing to remember, nothing to forget.
BRANCH="$(git -C "$ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
if [[ -n "$BRANCH" && "$BRANCH" != "HEAD" ]]; then
    # HTML label: only characters a ref may actually contain.
    BRANCH_HTML="$(printf '%s' "$BRANCH" | tr -cd 'A-Za-z0-9._/-')"
    # shields.io: `_` renders as a space and `__` as an underscore, `/` as %2F.
    BRANCH_BADGE="$(printf '%s' "$BRANCH" | sed 's/_/__/g; s|/|%2F|g')"
    # `[^>]*>` because a label may carry a class before its content
    sed -i -E "s|(id=\"header-branch\"[^>]*>)[^<]*|\1${BRANCH_HTML}|" "$INDEX"
    sed -i -E "s|(id=\"preloader-branch\"[^>]*>)[^<]*|\1${BRANCH_HTML}|" "$INDEX"
    sed -i -E "s|(id=\"about-branch-label\"[^>]*>)[^<]*|\1${BRANCH_HTML}|" "$INDEX"
    sed -i -E "s|(badge/branch-)[A-Za-z0-9._%~-]*|\1${BRANCH_BADGE}|" "$README"
fi

echo "Bumped version to v${VERSION} (asset stamp ${STAMP}) in:"
echo "  - index.html (2 badges + ?v= queries)"
echo "  - manifest.json"
echo "  - sw.js (CACHE)"
echo "  - assets/js/app.js (_I18N_VERSION)"
echo "  - README.md (version badge)"
echo "  - index.html + README.md (branch label, from git)"
echo
echo "Remember to add a CHANGELOG.md entry for v${VERSION} and to prepend the"
echo "new release to the README \"What's new\" line (prose stays manual)."
