#!/bin/bash
# Fix ownership and permissions for EverShelf runtime directories.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WEB_USER="${WEB_USER:-www-data}"

chown -R "${WEB_USER}:${WEB_USER}" "${ROOT}/data" "${ROOT}/logs" 2>/dev/null || true
chmod 750 "${ROOT}/data" "${ROOT}/logs"
chmod 640 "${ROOT}/.env" 2>/dev/null || true
find "${ROOT}/data" -type f -exec chmod 660 {} \;
find "${ROOT}/logs" -type f -exec chmod 640 {} \;
echo "Permissions updated for ${WEB_USER}"
