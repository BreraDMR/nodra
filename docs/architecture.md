# Architecture and decisions

NODRA is a local commerce demonstration, not a production merchant. The contract in `api/config/api_doc/shop.yaml` was written before persistence and feature implementation, following the API → schema → code sequence of the Max standard.

## System boundaries

- **Next.js:** server-rendered public pages, localized UI, browser basket and admin client. Browser requests use a same-origin rewrite to Symfony. Catalogue pages fetch without caching so admin edits are immediately visible.
- **Symfony:** validates request DTOs, authenticates the administrator with a server-side session, enforces CSRF on admin writes, calculates all checkout totals, reserves inventory and returns JSON. Controllers translate errors into HTTP responses; services hold commerce rules.
- **PostgreSQL:** products, variants, prices, stock movements, orders, order item snapshots and admin users. UUIDs avoid sequential public identifiers. Check constraints keep stock and prices nonnegative.

## Checkout invariants

Each checkout request has an idempotency key bound to a hash of the normalized locale, customer and basket. Repeating it with the same details returns the original order; changing the details returns a conflict. Selected variants are locked in a transaction, then checked for publication, active status and available stock. The server uses current prices, never browser basket prices. The order stores a monetary and product-copy snapshot. A 64-character lookup token is required to read its receipt by reference. No payment gateway is involved.

## Admin workflow

Admin writes require an authenticated session and CSRF token. A product can be drafted or published; an initial variant is created with zero stock, then stocked through a reasoned adjustment. Existing products can be edited, and each variant stock adjusted separately. Orders follow `placed → processing → shipped → completed`, or may be cancelled before shipment. Cancellation returns reserved stock and records movements.

## Local demo limits

Product media is selected by an image path, with no upload pipeline. Existing variant labels and price tiers can be stocked individually, while the product editor changes the first variant's base price. Shipping uses fixed illustrative amounts by destination. There is no payment capture, email, tax calculation, promotion engine, refund or fulfillment integration. Public deployment needs a separate security, accessibility, legal and operational review.
