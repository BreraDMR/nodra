#!/usr/bin/env bash
# Restore a dump INTO A COPY of the stand database, never into the stand itself.
# The copy database app_restore is dropped and recreated from the given dump.
# Usage: scripts/restore.sh api/var/backups/app-2026-09-29-1905.sql
set -euo pipefail
cd "$(dirname "$0")/.."

CONTAINER=${CONTAINER:-api-database-1}
STAND_DB=${DB:-app}
COPY_DB=${COPY_DB:-app_restore}
USER=${USER_NAME:-app}
DUMP=${1:-}

if [ -z "$DUMP" ]; then
  echo "Usage: $0 api/var/backups/app-YYYY-MM-DD-HHMM.sql" >&2
  exit 1
fi
if [ ! -f "$DUMP" ]; then
  echo "Dump file not found: $DUMP" >&2
  exit 1
fi
if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
  echo "Container $CONTAINER is not running; start the stand first (scripts/dev.sh)." >&2
  exit 1
fi
if [ "$COPY_DB" = "$STAND_DB" ]; then
  echo "Refusing: the copy must not be the stand database ($STAND_DB)." >&2
  exit 1
fi

echo "Restoring $DUMP into the copy database $COPY_DB (stand $STAND_DB is untouched)"
docker exec "$CONTAINER" psql -U "$USER" -d postgres \
  -c "DROP DATABASE IF EXISTS $COPY_DB" -c "CREATE DATABASE $COPY_DB" >/dev/null
docker exec -i "$CONTAINER" psql -U "$USER" -d "$COPY_DB" -q < "$DUMP"

echo "Sanity counts in $COPY_DB:"
docker exec "$CONTAINER" psql -U "$USER" -d "$COPY_DB" -tc \
  "SELECT 'products ' || COUNT(*) FROM product UNION ALL
   SELECT 'variants ' || COUNT(*) FROM product_variant UNION ALL
   SELECT 'orders ' || COUNT(*) FROM shop_order UNION ALL
   SELECT 'claims ' || COUNT(*) FROM return_claim"
echo "Drop the copy when done: docker exec $CONTAINER psql -U $USER -d postgres -c 'DROP DATABASE $COPY_DB'"
