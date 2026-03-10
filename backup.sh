#!/bin/bash
# Daily backup of Dispensa database to GitHub
# Runs via cron: commits and pushes data/dispensa.db

cd /var/www/html/dispensa || exit 1

# Only commit if there are actual changes
if git diff --quiet data/ 2>/dev/null && git diff --cached --quiet data/ 2>/dev/null; then
    # Check for untracked files in data/
    if [ -z "$(git ls-files --others --exclude-standard data/)" ]; then
        exit 0  # Nothing changed
    fi
fi

DATE=$(date '+%Y-%m-%d %H:%M')
git add data/dispensa.db
git commit -m "📦 Backup database automatico - $DATE"
git push
