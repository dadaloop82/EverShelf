#!/usr/bin/env bash
# Regenerate the cheap-to-read code indexes used to avoid loading the huge
# monolithic files (api/index.php ~19k lines, assets/js/app.js ~25k lines).
#
# Usage:  bash scripts/gen-code-index.sh
# Output: docs/INDEX-api-index.md, docs/INDEX-app-js.md, docs/INDEX-actions.md
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

mkdir -p docs

# ── api/index.php function index ────────────────────────────────────────────
{
  echo '# Index — api/index.php'
  echo
  echo 'Auto-generated top-level function index. Regenerate: `bash scripts/gen-code-index.sh`.'
  echo 'Use with: `sed -n "<line>p" api/index.php` (or the editor line range) to jump to a function.'
  echo
  echo '```'
  grep -nE '^function [A-Za-z_]+' api/index.php | sed -E 's/\(.*//'
  echo '```'
} > docs/INDEX-api-index.md

# ── assets/js/app.js function index ─────────────────────────────────────────
{
  echo '# Index — assets/js/app.js'
  echo
  echo 'Auto-generated top-level function index. Regenerate: `bash scripts/gen-code-index.sh`.'
  echo 'Use with: `sed -n "<line>p" assets/js/app.js` to jump to a function.'
  echo
  echo '```'
  grep -nE '^(async )?function [A-Za-z_$]+' assets/js/app.js | sed -E 's/\(.*//'
  echo '```'
} > docs/INDEX-app-js.md

# ── API action → handler map (from the router switch) ───────────────────────
{
  echo '# Index — API actions (router switch in api/index.php)'
  echo
  echo 'Auto-generated action → handler mapping. Regenerate: `bash scripts/gen-code-index.sh`.'
  echo
  echo '| Line | Action | Handler |'
  echo '|------|--------|---------|'
  awk '
  match($0, /case '"'"'([a-z_]+)'"'"':/) {
    action = substr($0, RSTART+6, RLENGTH-8);
    ln = NR;
    getline nxt;                       # handler is on the following line
    if (match(nxt, /[a-zA-Z_]+\(/)) {
      handler = substr(nxt, RSTART, RLENGTH-1);
    } else {
      handler = "—";
    }
    printf "| %d | %s | %s |\n", ln, action, handler;
  }
' api/index.php
} > docs/INDEX-actions.md

echo "Regenerated:"
echo "  docs/INDEX-api-index.md  ($(wc -l < docs/INDEX-api-index.md) lines)"
echo "  docs/INDEX-app-js.md     ($(wc -l < docs/INDEX-app-js.md) lines)"
echo "  docs/INDEX-actions.md    ($(wc -l < docs/INDEX-actions.md) lines)"
