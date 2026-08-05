#!/usr/bin/env bash
# Check the migrations the hard way on a throwaway copy of the stand database:
# dump app into app_migcheck, migrate up to latest, down to zero, up again.
# The stand database is only read. The copy is dropped at the end unless --keep.
set -euo pipefail
cd "$(dirname "$0")/.."

CONTAINER=${CONTAINER:-api-database-1}
STAND_DB=${DB:-app}
COPY_DB=${COPY_DB:-app_migcheck}
USER=${USER_NAME:-app}
KEEP=${1:-}

if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
  echo "Container $CONTAINER is not running; start the stand first (scripts/dev.sh)." >&2
  exit 1
fi
if [ "$COPY_DB" = "$STAND_DB" ]; then
  echo "Refusing: the copy must not be the stand database ($STAND_DB)." >&2
  exit 1
fi

echo "Copying $STAND_DB into $COPY_DB (the stand is only read)"
docker exec "$CONTAINER" psql -U "$USER" -d postgres \
  -c "DROP DATABASE IF EXISTS $COPY_DB" -c "CREATE DATABASE $COPY_DB" >/dev/null
docker exec "$CONTAINER" pg_dump -U "$USER" -d "$STAND_DB" \
  | docker exec -i "$CONTAINER" psql -U "$USER" -d "$COPY_DB" -q

# The doctrine config reads DATABASE_URL from the environment; take it from
# api/.env and swap the database name for the copy, without printing it.
set -a
# shellcheck disable=SC1091
[ -f api/.env ] && . api/.env
set +a
COPY_URL=$(printf '%s' "$DATABASE_URL" | sed "s|/$STAND_DB?|/$COPY_DB?|; s|/$STAND_DB\$|/$COPY_DB|")
if [ "$COPY_URL" = "$DATABASE_URL" ]; then
  echo "Could not point DATABASE_URL at $COPY_DB; nothing was changed in $STAND_DB." >&2
  docker exec "$CONTAINER" psql -U "$USER" -d postgres -c "DROP DATABASE $COPY_DB" >/dev/null
  exit 1
fi

finish() {
  if [ "$KEEP" = "--keep" ]; then
    echo "The copy $COPY_DB stays for inspection."
  else
    docker exec "$CONTAINER" psql -U "$USER" -d postgres -c "DROP DATABASE $COPY_DB" >/dev/null
    echo "Copy dropped."
  fi
}
trap finish EXIT

echo "--- up to latest ---"
(cd api && DATABASE_URL="$COPY_URL" php bin/console doctrine:migrations:migrate -n --no-debug) | tail -2
echo "--- down to zero ---"
(cd api && DATABASE_URL="$COPY_URL" php bin/console doctrine:migrations:migrate first -n --no-debug) | tail -2
echo "--- up again ---"
(cd api && DATABASE_URL="$COPY_URL" php bin/console doctrine:migrations:migrate -n --no-debug) | tail -2
echo "Migrations are reversible on a copy of the real data."
