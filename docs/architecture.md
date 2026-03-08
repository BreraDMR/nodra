# Architecture and decisions

NODRA is a local commerce demonstration, not a production merchant. The contract in `api/config/api_doc/shop.yaml` was written before persistence and feature implementation, following the API → schema → code sequence of the Max standard.

## System boundaries

- **Next.js:** server-rendered public pages, localized UI, browser basket and admin client. Browser requests use a same-origin rewrite to Symfony. Public catalogue fetches revalidate after 15 seconds. Checkout reads fresh prices and stock directly from Symfony, so cached storefront availability is only a short-lived display hint.
- **Symfony:** validates request DTOs, authenticates the administrator with a server-side session, enforces CSRF on admin writes, calculates all checkout totals, reserves inventory and returns JSON. Controllers translate errors into HTTP responses; services hold commerce rules.
- **PostgreSQL:** products, variants, dated supplier offers, prices, stock movements, orders, order item snapshots, admin users, customer accounts and loyalty entries. UUIDs avoid sequential public identifiers. Check constraints keep stock and prices nonnegative.

## Checkout invariants

Each checkout request has an idempotency key bound to a hash of the normalized locale, customer, basket and optional account. Repeating it with the same details returns the original order; changing the details returns a conflict. Selected variants are locked in a transaction, then checked for publication, active status and available stock. The server uses current prices, never browser basket prices. The order stores a monetary and product-copy snapshot. A 64-character lookup token is required to read its receipt by reference. No payment gateway is involved.

## Admin workflow

Admin writes require an authenticated session and CSRF token. A product can be drafted or published; an initial variant is created with zero stock, then stocked through a reasoned adjustment. Existing products can be edited, and each variant stock adjusted separately. Orders follow `placed → processing → shipped → completed`, or may be cancelled before shipment. Cancellation returns reserved stock and records movements.

## Growth baseline

Public lists return 12 cards per page. Next caches each public API request for 15 seconds, and successful catalogue API responses permit a shared cache to retain them for the same period. Product name search has locale-specific PostgreSQL trigram indexes. Admin lists are paginated at 24 products and 30 orders; product variants and supplier offers are each fetched in one query per page. Account history is paginated at 20 entries. The search index extension is `pg_trgm`. A fresh isolated database with 680 published cards returned page 20 of a 600-card search with bounded queries; this checks query shape, not traffic capacity.

The image field accepts local paths and can accept HTTPS media from one configured `MEDIA_ORIGIN`. For a public deployment, serve media and HTML through a CDN and use shared session/cache storage across app instances. Add request rate limits, background email delivery, metrics, database backups and load tests against the intended infrastructure before claiming a traffic target. Current local PHP and Next development servers are not a production performance benchmark.

## Accounts and loyalty

Google OpenID Connect supplies a stable `sub` identity and verified email; the OAuth state is checked against the browser session. A development-only account allows local review without Google credentials. New accounts receive a welcome message through the configured Symfony Mailer transport, which points to Mailpit locally. A signed-in checkout must use the account email. Completing an order writes exactly one loyalty ledger entry, based on product subtotal only: `floor(CZK minor / 10000)` or `floor(EUR minor / 400)`, minimum one point. The unique order constraint prevents duplicate awards. The account summary returns the full balance with up to 20 ledger entries per page. Points are accrued but cannot be spent in this demo.

## Local demo limits

Product media is selected by an image path, with no upload pipeline. Variants can be created and their labels, prices, active status and stock managed separately. The product editor also changes the base price of the first available variant. Delivery is accepted only within Czechia for every locale, with a required region/district and Czech postal code; the fixed fee is 89 Kč or 3.90 €. Mailpit captures local welcome emails; real outbound SMTP, Google OAuth credentials, payment capture, tax calculation, promotion engine, refunds and fulfillment are not configured. Allegro prices and visible quantities are recorded as dated `supplier_offer` snapshots and in `docs/catalog-sources.md`; they are not synchronized with sellers. Demo variant stock is still based on those snapshots and must be replaced with owned-stock or order-request logic before a real sale. See `docs/real-commerce-roadmap.md`.
