#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
(cd api && php bin/console lint:container && php bin/console lint:yaml config/api_doc/shop.yaml && php bin/console doctrine:schema:validate && ./vendor/bin/phpunit)
(cd web && npm run lint && npm run format:check && npm run build)
