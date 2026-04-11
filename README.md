# NODRA

A portfolio cycling equipment store built with React/Next.js 16, Symfony 8 and PostgreSQL. Its 80 demo cards feature real product models researched on Allegro.cz; the retailer, stock and product imagery are illustrative snapshots. Checkout does not collect payment or arrange shipment.

## Run locally

Requirements: PHP 8.4+, Composer, Node.js 22+, npm and Docker with Compose. Ports 3000, 8000, 55432, 1025 and 8025 must be available.

```bash
./scripts/setup.sh
./scripts/dev.sh
```

Open [the Czech storefront](http://127.0.0.1:3000/cs), [German storefront](http://127.0.0.1:3000/de), [English storefront](http://127.0.0.1:3000/en), [customer account](http://127.0.0.1:3000/cs/account), [admin](http://127.0.0.1:3000/admin), or [local email inbox](http://127.0.0.1:8025). The account page includes a development-only demo sign-in. Demo admin: `admin@nodra.test` / `NodraDemo2026!`. Replace this credential before any public deployment. Stop the web servers with Ctrl+C and containers with `cd api && docker compose down`. To reset all demo orders and products, run `cd api && php bin/console doctrine:fixtures:load --no-interaction` while the database is running. To add catalog cards to an existing local database without resetting orders or edited stock, run `cd api && php bin/console app:catalog:import`.

Google sign-in uses an OAuth 2.0 web application client. Register `http://127.0.0.1:3000/api/account/google/callback` as an authorized redirect URI in Google Cloud, then put `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in ignored `api/.env.local`. The public account button redirects to Google once these values are set. The local demo sign-in and points work without credentials. Welcome emails are delivered only to Mailpit at port 8025; a real SMTP transport is not configured.

Run verification with `./scripts/check.sh`; it prepares the `app_test` database and runs the integration tests, each rolled back after it finishes. The API contract is in [`api/config/api_doc/shop.yaml`](api/config/api_doc/shop.yaml). After pulling schema changes into an existing local database, run `cd api && php bin/console doctrine:migrations:migrate -n && php bin/console app:catalog:import`: the import adds missing categories and products and only fills blank fields, so admin edits stay.

## What works

- Czech, German and English routes, localized product copy and currency formatting
- 80 sourced demo cards in a managed category tree (components with tyres, tubes, chains, cassettes, brake pads, cables, shifting; accessories, lights, bags, apparel), with search by name, brand, MPN or EAN, sorting, bounded pagination, variant selection and available quantities; each source link, observed price and seller quantity is recorded in [`docs/catalog-sources.md`](docs/catalog-sources.md)
- Compatibility filters: categories define typed attributes (choice, number, text) inherited down the tree; products and variants carry values, a variant value overriding the product one; category facets list brands and filterable values with counts
- Exact variants with MPN and check-digit-validated, unique EAN; breadcrumbs, spec tables, package contents and brand/MPN/GTIN in product structured data
- Browser basket and Czech-only delivery in every locale, with required region/district, server-side repricing, inventory reservation, idempotent order creation and private order lookup token
- Customer account with Google sign-in integration, local demo sign-in and a welcome email; one loyalty point per 100 Kč or 4 € of product subtotal after a signed-in order reaches `completed`
- Admin session, dashboard, category tree editor with attribute definitions, product and variant editing/creation with attributes and identifiers, dated supplier offers matched to exact variants with seller and lead time, stock adjustments, order queue and status workflow
- Original AI-generated demo imagery and a responsive storefront and admin interface

## Architecture

Next server components render catalogue pages using the Symfony API. Client components handle basket, checkout and admin interactions. Next rewrites same-origin `/api/*` calls to the local Symfony server so browser sessions work without cross-origin cookies. Public catalogue data has a 15-second cache lifetime; prices and stock are always checked again by the API at checkout. PostgreSQL transactions lock selected variants before reserving stock. Money is stored as integer minor units; order items preserve the purchased name, variant and price. Admin products and orders are paginated, and product variants and supplier offers are fetched in batches.

For a future image CDN, set `MEDIA_ORIGIN` to its HTTPS origin or path when building `web/`, then store media URLs from that origin in product images. Local images continue to work without this variable. See [`docs/architecture.md`](docs/architecture.md) for the growth baseline and deployment work that remains.

`api/` contains entities, migrations, fixtures, controllers and services. `web/` contains React routes and components. `docs/architecture.md` records the main decisions and demo boundaries.

The [80 Allegro → NODRA link pairs](docs/allegro-nodra-links.md) let you compare every sourced card. The [real-commerce roadmap](docs/real-commerce-roadmap.md) describes the supplier-backed order flow and the work required before accepting actual sales.

The [commerce concept and implementation sequence](docs/commerce-concept.md) record the owner's retailer positioning, planned delivery and installation services, pricing, reviews and the decisions still pending.

The [active development plan](docs/development-plan.md) is the ordered backlog for turning the demo into a supplier-backed Czech merchant. It includes dependencies, delivery milestones and acceptance gates; completed demo work remains in `PLAN.md`.

## Demo boundaries

NODRA is not a real merchant. Delivery is restricted to Czechia regardless of interface language. Shipping values and Allegro stock snapshots are illustrative; no live supplier or fulfillment integration exists. Payment processing, real email delivery, point redemption, taxes, refunds, media uploads and legal commerce pages are outside the demo. Product images were generated for this project and are not official manufacturer photography. No customer reviews are fabricated in the seeded data.

## Publication status

This repository has no GitHub remote and has not been pushed. Its local history uses the retrospective 2026-01-10 to 2026-06-20 dates requested for portfolio presentation; those timestamps do not represent elapsed development time. Review the demo credential, production security and legal obligations before any public deployment.
