#!/bin/sh
# EverShelf container entrypoint.
#
# Bind mounts (Synology /volume1/…, Unraid appdata, a host ./data) shadow the
# image's build-time `chown www-data`. Apache then runs as UID 33 and cannot
# create evershelf.db — the splash fails with "Data directory / Disk write /
# Database connection". Fix ownership (and, as a last resort, mode) before
# handing off to cron + Apache.
set -eu

DATA_DIR="${EVERSHELF_DATA_DIR:-/var/www/html/data}"
LOG_DIR="/var/www/html/logs"
WEB_USER="${APACHE_RUN_USER:-www-data}"
WEB_GROUP="${APACHE_RUN_GROUP:-www-data}"

mkdir -p \
    "$DATA_DIR/backups" \
    "$DATA_DIR/rate_limits" \
    "$DATA_DIR/logs" \
    "$LOG_DIR" \
    2>/dev/null || true

if [ "$(id -u)" = "0" ]; then
    chown -R "$WEB_USER:$WEB_GROUP" "$DATA_DIR" "$LOG_DIR" 2>/dev/null || true
    chmod -R u+rwX,g+rwX "$DATA_DIR" "$LOG_DIR" 2>/dev/null || true

    # Probe as the web user: Synology shared-folder ACLs sometimes refuse chown
    # while still allowing a mode change.
    if ! su -s /bin/sh "$WEB_USER" -c "test -w '$DATA_DIR' && touch '$DATA_DIR/.evershelf_write_ok' && rm -f '$DATA_DIR/.evershelf_write_ok'" 2>/dev/null; then
        echo "evershelf: WARNING — $DATA_DIR is not writable by $WEB_USER after chown; opening a+rwX (typical Synology / NAS bind mount). Prefer fixing the host with: chown -R 33:33 <host-data-path>" >&2
        chmod -R a+rwX "$DATA_DIR" 2>/dev/null || true
        chown -R "$WEB_USER:$WEB_GROUP" "$DATA_DIR" 2>/dev/null || true
    fi
fi

exec "$@"
