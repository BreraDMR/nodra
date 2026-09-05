# NODRA

A portfolio cycling equipment store built with React/Next.js 16, Symfony 8 and PostgreSQL. Its 85 demo cards feature real product models researched on Czech marketplaces; the retailer, stock and product imagery are illustrative snapshots. Checkout does not collect payment or arrange shipment; an order is a request that NODRA confirms by hand, quotes and walks through procurement, delivery and a recorded cash or transfer payment.

## Run locally

Requirements: PHP 8.4+, Composer, Node.js 22+, npm and Docker with Compose. Ports 3000, 8000, 55432, 1025 and 8025 must be available.

```bash
./scripts/setup.sh
./scripts/dev.sh
```

Open [the Czech storefront](http://127.0.0.1:3000/cs), [German storefront](http://127.0.0.1:3000/de), [English storefront](http://127.0.0.1:3000/en), [customer account](http://127.0.0.1:3000/cs/account), [admin](http://127.0.0.1:3000/admin), or [local email inbox](http://127.0.0.1:8025). The admin needs a credentials row in the stand database; no password ships in the code, so create your own admin user before signing in. The account page's demo sign-in works in development, and elsewhere only after starting the API with `APP_DEMO_LOGIN=1`. Stop the web servers with Ctrl+C and containers with `cd api && docker compose down`. To reset all demo orders and products, run `cd api && php bin/console doctrine:fixtures:load --no-interaction` while the database is running. To add catalog cards to an existing local database without resetting orders or edited stock, run `cd api && php bin/console app:catalog:import`.

### The explicit portfolio demo

The same code builds two things. The ordinary build is a shop; a build with `NEXT_PUBLIC_DEMO_MODE=1` is an explicit portfolio demo: every page carries a banner naming the demo («Ukázka portfolia — objednávky jsou testovací, platby ani dodání se neprovádějí» and its de/en versions), checkout and the receipt say the order is a test, and the shared category illustrations are labeled as illustrations instead of posing as product photos. Screenshots and a short scripted walkthrough live in [`docs/screenshots/`](docs/screenshots) and [`docs/screencast/demo-scenario.webm`](docs/screencast/demo-scenario.webm); the cards meant for a demo run and the filter paths that hold are listed in [`docs/showcase-cards.md`](docs/showcase-cards.md); the case study is [`docs/case-study.md`](docs/case-study.md).

Google sign-in uses an OAuth 2.0 web application client. Register `http://127.0.0.1:3000/api/account/google/callback` as an authorized redirect URI in Google Cloud, then put `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in ignored `api/.env.local`. The public account button redirects to Google once these values are set. The local demo sign-in and points work without credentials. Welcome emails are delivered only to Mailpit at port 8025; a real SMTP transport is not configured.

Run verification with `./scripts/check.sh`; it prepares the `app_test` database and runs the integration tests, each rolled back after it finishes. The API contract is in [`api/config/api_doc/shop.yaml`](api/config/api_doc/shop.yaml). After pulling schema changes into an existing local database, run `cd api && php bin/console doctrine:migrations:migrate -n && php bin/console app:catalog:import`: the import adds missing categories and products and only fills blank fields, so admin edits stay. To try the merchant workflow without touching the stand's data, run a second stack against a database copy: `scripts/restore.sh <dump>` restores into `app_restore`, a copy of `api/` with its `.env` pointed there serves on another port (8001), and `web/` follows with `API_PROXY_URL` and `API_INTERNAL_URL` set to it before `npm run build && npm start`.

## What works

- Czech, German and English routes, localized product copy and currency formatting
- 85 sourced demo cards in a managed category tree (components with tyres, tubes, chains, cassettes, brake pads, cables, shifting; accessories, lights, bags, apparel), with search by name, brand, MPN or EAN, sorting, bounded pagination, variant selection and available quantities; each card keeps its observed price and seller quantity as a dated supplier-offer snapshot
- Compatibility filters: categories define typed attributes (choice, number, text) inherited down the tree; products and variants carry values, a variant value overriding the product one; category facets list brands and filterable values with counts
- Exact variants with MPN and check-digit-validated, unique EAN; breadcrumbs, spec tables, package contents and brand/MPN/GTIN in product structured data; half of the demo variants carry a matched, dated supplier offer, so the storefront shows real lead times and `orderable` availability
- Browser basket and Czech-only delivery in every locale, with required region/district, server-side repricing, inventory reservation, idempotent order creation and private order lookup token
- Customer account with Google sign-in integration, local demo sign-in and a welcome email; one loyalty point per 100 Kč or 4 € of product subtotal after a signed-in order reaches `completed`, points following any later refund or voided payment
- A full merchant workflow behind the storefront: the admin confirms each order with the customer's channel, buys supplier-backed lines from matched offers with recorded inbound cost and exchange rate, schedules and hands over shipments, and records payments and refunds in an append-only ledger; nine work queues, corrections with reasons, and economics (cost, expected result, margin) per order
- After-sales: a claims registry for 14-day returns and 24-month warranty cases with Prague-calendar deadlines counted from the day the customer contacted the shop, and refunds tagged to one claim each; evening installation bookings with a preliminary window, a confirmed work window, a per-evening limit and a travel gap between windows
- Admin products and orders are paginated; dashboard tiles track revenue, work queues, pricing alerts, import problems and open after-sales cases
- Rate limiting on public catalog, checkout and admin sign-in, a JSON error journal for every 4xx+ API response, and local backup/restore/migration-rehearsal scripts in `scripts/`
- Original AI-generated demo imagery and a responsive storefront and admin interface; in the demo build the shared category scenes are labeled as illustrations (real per-product photography is future work, D07.2)

## Architecture

Next server components render catalogue pages using the Symfony API. Client components handle basket, checkout and admin interactions. Next rewrites same-origin `/api/*` calls to the local Symfony server so browser sessions work without cross-origin cookies. Public catalogue data has a 15-second cache lifetime; prices and stock are always checked again by the API at checkout. PostgreSQL transactions lock selected variants before reserving stock. Money is stored as integer minor units; order items preserve the purchased name, variant and price. Admin products and orders are paginated, and product variants and supplier offers are fetched in batches.

For a future image CDN, set `MEDIA_ORIGIN` to its HTTPS origin or path when building `web/`, then store media URLs from that origin in product images. Local images continue to work without this variable. See [`docs/architecture.md`](docs/architecture.md) for the growth baseline and deployment work that remains.

`api/` contains entities, migrations, fixtures, controllers and services. `web/` contains React routes and components. `docs/architecture.md` records the main decisions and demo boundaries.

Seven engineering decisions worth an interview answer are in [`docs/how-it-works.md`](docs/how-it-works.md); [`docs/perf-local.md`](docs/perf-local.md) records a local, sequential latency measurement against a 1 050-card copy of the catalog — a data-volume check, not a production load test. The short case study is [`docs/case-study.md`](docs/case-study.md), and [`docs/demo-deployment.md`](docs/demo-deployment.md) describes how a hosted demo would be released and rolled back.

## Demo boundaries

NODRA is not a real merchant. Delivery is restricted to Czechia regardless of interface language. Shipping values and supplier stock snapshots are illustrative; no live supplier or fulfillment integration exists. Payment processing, real email delivery, point redemption, taxes, media uploads and legal commerce pages are outside the demo; refunds move through the ledger but no card charge is ever reversed. Product images were generated for this project and are not official manufacturer photography. No customer reviews are fabricated in the seeded data, and outside ratings live in an admin-only registry until the owner decides what may be published. The installation works list ships empty on purpose: until the owner fills it, nothing on the storefront offers installation for sale.

## Verification

`./scripts/check.sh` passes with 300 PHPUnit tests (28628 assertions) plus 10 web tests, ESLint, Prettier and the production build. No secret usable in a published demo ships in the code: the fixtures create the admin with the well-known password in development only — any other environment must set `APP_ADMIN_PASSWORD` or receives a generated random one, and OAuth and mail transports live in ignored env files. Review production security and legal obligations before any public deployment.
