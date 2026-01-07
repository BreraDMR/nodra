#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
command -v docker >/dev/null || { echo 'Docker is required.' >&2; exit 1; }
command -v php >/dev/null || { echo 'PHP 8.4+ is required.' >&2; exit 1; }
command -v composer >/dev/null || { echo 'Composer is required.' >&2; exit 1; }
command -v node >/dev/null || { echo 'Node.js 22+ is required.' >&2; exit 1; }
(cd api && composer install --no-interaction && docker compose up -d --wait && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:fixtures:load --no-interaction)
(cd web && npm ci)
echo 'NORDRA is ready. Run ./scripts/dev.sh and open http://127.0.0.1:3000/cs'
