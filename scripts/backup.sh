#!/usr/bin/env bash
# Backup the stand database (app) into api/var/backups/ as plain SQL.
# The stand keeps running; the dump only reads. Never restores anything.
set -euo pipefail
cd "$(dirname "$0")/.."

CONTAINER=${CONTAINER:-api-database-1}
DB=${DB:-app}
USER=${USER_NAME:-app}
DIR=api/var/backups

if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
  echo "Container $CONTAINER is not running; start the stand first (scripts/dev.sh)." >&2
  exit 1
fi

mkdir -p "$DIR"
FILE="$DIR/app-$(date +%Y-%m-%d-%H%M).sql"
docker exec "$CONTAINER" pg_dump -U "$USER" -d "$DB" > "$FILE"
LINES=$(wc -l < "$FILE" | tr -d ' ')
echo "Dumped $DB into $FILE ($LINES lines)"
echo "Old dumps stay where they are; clean $DIR by hand when it grows."
