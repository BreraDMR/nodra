#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
(cd api && docker compose up -d --wait && php bin/console doctrine:migrations:migrate --no-interaction)
(cd api && php -S 127.0.0.1:8000 -t public public/index.php) &
api_pid=$!
(cd web && npm run dev -- --hostname 127.0.0.1) &
web_pid=$!
cleanup() { kill "$api_pid" "$web_pid" 2>/dev/null || true; wait "$api_pid" "$web_pid" 2>/dev/null || true; }
trap cleanup EXIT INT TERM
printf '\nNORDRA storefront: http://127.0.0.1:3000/cs\nNORDRA admin:      http://127.0.0.1:3000/admin\nPress Ctrl+C to stop the web servers.\n\n'
wait "$web_pid"
