#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
(cd api && php bin/console lint:container && php bin/console lint:yaml config/api_doc/shop.yaml && php bin/console doctrine:schema:validate \
  && php bin/console --env=test doctrine:database:create --if-not-exists && php bin/console --env=test doctrine:migrations:migrate --no-interaction --allow-no-migration \
  && ./vendor/bin/phpunit)
(cd web && npm run test && npm run lint && npm run format:check && npm run build)
