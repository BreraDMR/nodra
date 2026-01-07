# NORDRA

A fictional cycling equipment store built for a full-stack portfolio. The editorial storefront and operations dashboard are React/Next.js 16 applications backed by a Symfony 8 JSON API and PostgreSQL. All products, images and orders are demo material. Checkout does not collect payment or arrange shipment.

## Run locally

Requirements: PHP 8.4+, Composer, Node.js 22+, npm and Docker with Compose. Ports 3000, 8000 and 55432 must be available.

```bash
./scripts/setup.sh
./scripts/dev.sh
```

Open [the Czech storefront](http://127.0.0.1:3000/cs), [German storefront](http://127.0.0.1:3000/de), [English storefront](http://127.0.0.1:3000/en), or [admin](http://127.0.0.1:3000/admin). Demo admin: `admin@nordra.test` / `NordraDemo2026!`. This credential is seeded for local review and must be replaced before any public deployment. Stop the two web servers with Ctrl+C. Stop the database with `cd api && docker compose down`. To reset demo orders and products, run `cd api && php bin/console doctrine:fixtures:load --no-interaction` while the database is running.

Run verification with `./scripts/check.sh`. The API contract is in [`api/config/api_doc/shop.yaml`](api/config/api_doc/shop.yaml).

## What works

- Czech, German and English routes, localized product copy and currency formatting
- Product catalogue with category, search, sorting and pagination; variant selection and inventory availability
- Browser basket, guest checkout, server-side repricing, inventory reservation, idempotent order creation and private order lookup token
- Admin session, dashboard, product editing/creation, stock adjustments, order queue and status workflow
- Original AI-generated demo imagery and a responsive storefront and admin interface

## Architecture

Next server components render catalogue pages using the Symfony API. Client components handle basket, checkout and admin interactions. Next rewrites same-origin `/api/*` calls to the local Symfony server so browser sessions work without cross-origin cookies. The basket is stored in browser local storage, but prices and stock are always checked again by the API at checkout. PostgreSQL transactions lock selected variants before reserving stock. Money is stored as integer minor units; order items preserve the purchased name, variant and price.

`api/` contains entities, migrations, fixtures, controllers and services. `web/` contains React routes and components. `docs/architecture.md` records the main decisions and demo boundaries.

## Demo boundaries

NORDRA is not a real merchant. Shipping values are illustrative; taxes, real shipping rates, payment processing, email delivery, refunds, promotions, media uploads and legal commerce pages are outside the demo. For other countries the English checkout uses a flat illustrative EUR shipping amount. Images were generated for this project. No customer reviews or transaction history are fabricated in the seeded data.

## Publication status

This repository has no GitHub remote and has not been pushed. Its local history uses the retrospective 2026-01-10 to 2026-06-20 dates requested for portfolio presentation; those timestamps do not represent elapsed development time. Review the demo credential, production security and legal obligations before any public deployment.
