#!/bin/bash
# Daily backup of EverShelf database (local only)
# Retention follows BACKUP_RETENTION_DAYS from .env (default 3)
#
# The database runs in WAL mode, so copying the .db file alone can lose whatever
# is still sitting in the -wal file. Use SQLite's own online backup instead: it
# stays consistent even while the app is writing. sqlite3 (CLI) is preferred;
# without it the script flushes the WAL through PHP and copies afterwards.

set -euo pipefail
INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
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
DEST="${BACKUP_DIR}/evershelf_${DATE}.db"
TMP="${DEST}.part"
# A failed run must not leave a half-written file behind for the retention step
trap 'rm -f "$TMP"' EXIT

if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "$DB_FILE" ".backup '${TMP}'"
else
    php -r '
        $src = $argv[1];
        try {
            $pdo = new PDO("sqlite:" . $src);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("PRAGMA wal_checkpoint(FULL)");
        } catch (Throwable $e) {
            fwrite(STDERR, "WAL checkpoint failed: " . $e->getMessage() . "\n");
            exit(1);
        }
    ' "$DB_FILE" || { echo "backup.sh: need sqlite3 or a working PHP CLI" >&2; exit 1; }
    cp -- "$DB_FILE" "$TMP"
fi

mv -f -- "$TMP" "$DEST"

# Cron usually runs as root: keep the backup readable by the web app, as a
# backup made from the UI would be.
if [ "$(id -u)" -eq 0 ]; then
    chown --reference="$DB_FILE" "$DEST" 2>/dev/null || true
fi
chmod --reference="$DB_FILE" "$DEST" 2>/dev/null || true

# Keep only the newest N backups
ls -t "${BACKUP_DIR}"/evershelf_*.db 2>/dev/null | tail -n +$((RETENTION + 1)) | xargs -r rm --

