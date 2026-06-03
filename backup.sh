#!/bin/bash
# Daily backup of EverShelf database (local only)
# Retention follows BACKUP_RETENTION_DAYS from .env (default 3)

set -euo pipefail
INSTALL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${INSTALL_DIR}/data/backups"
ENV_FILE="${INSTALL_DIR}/.env"

RETENTION=3
if [ -f "$ENV_FILE" ]; then
    val=$(grep -E '^BACKUP_RETENTION_DAYS=' "$ENV_FILE" | tail -1 | cut -d= -f2)
    if [[ "$val" =~ ^[0-9]+$ ]] && [ "$val" -ge 1 ]; then
        RETENTION="$val"
    fi
fi

mkdir -p "$BACKUP_DIR"

DB_FILE="${INSTALL_DIR}/data/evershelf.db"
if [ ! -f "$DB_FILE" ]; then
    exit 0
fi

DATE=$(date '+%Y-%m-%d_%H%M')
cp "$DB_FILE" "${BACKUP_DIR}/evershelf_${DATE}.db"

# Keep only the newest N backups
ls -t "${BACKUP_DIR}"/evershelf_*.db 2>/dev/null | tail -n +$((RETENTION + 1)) | xargs -r rm --
