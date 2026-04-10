#!/bin/bash
# Daily backup of Dispensa database (local only)
# The database is NOT pushed to remote repositories.
# Runs via cron: creates a local timestamped backup copy
#
# Example crontab entry:
#   0 3 * * * /var/www/html/dispensa/backup.sh

INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_DIR="${INSTALL_DIR}/data/backups"

mkdir -p "$BACKUP_DIR"

DB_FILE="${INSTALL_DIR}/data/dispensa.db"
if [ ! -f "$DB_FILE" ]; then
    exit 0
fi

DATE=$(date '+%Y-%m-%d_%H%M')
cp "$DB_FILE" "${BACKUP_DIR}/dispensa_${DATE}.db"

# Keep only the last 7 backups
ls -t "${BACKUP_DIR}"/dispensa_*.db 2>/dev/null | tail -n +8 | xargs -r rm --
