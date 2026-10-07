# NODRA — a bike-parts store as an engineering case study

A full merchant platform for a small Prague bike-equipment shop: a trilingual
storefront, a catalog with compatibility filters and exact variants, a
supplier-backed order pipeline with procurement, hand-over and payments, an
after-sales claims registry, evening installation bookings and an admin that
runs the whole daily workflow. Built as a portfolio project; the demo labels
itself honestly (`docs/showcase-cards.md`).

**The 1-minute version:** a visitor browses 85 sourced product cards in Czech,
German and English, puts an exact variant in the basket and places an order —
which is a *request*, not a charge. Behind the storefront an admin confirms the
order with the customer, records the purchase from a supplier offer, receives
the goods, schedules and performs the hand-over and records the cash payment in
an append-only ledger. Returns and warranty claims run their Prague-calendar
deadlines; evening installations book with a per-evening capacity and a travel
gap. `./scripts/check.sh` holds it together: 300 API tests (28 628 assertions),
10 web tests, contract lint, schema validation, ESLint, Prettier, production
build.

| | |
|---|---|
| Task | Take a small bike shop online: catalog → order → procurement → payment on receipt → after-sales |
| Author's role | Damir owns the shop concept, the data (every sourced card has a recorded source and date), and the product decisions; implementation was driven through agentic AI coding sessions, with Damir reviewing, accepting and steering every milestone |
| Stack | Next.js 16 (server components + client basket/checkout/admin), Symfony 8 API, PostgreSQL 16, OpenAPI contract (`api/config/api_doc/shop.yaml`), Docker Compose |
| What is demo | Catalog data (sourced, dated snapshots), availability and lead times, illustrative imagery; no payments, shipping or real e-mails happen. The demo build says so on every page |

## Five decisions worth a conversation

Each of these is explained with code pointers and tests in
[`docs/how-it-works.md`](how-it-works.md); here are the trade-offs.

1. **The order remembers the moment of purchase.** Names, variant, promised
   lead time and price are snapshotted onto each line, so a later re-import or
   price change can never rewrite history. Trade-off: some duplication, in
   exchange for orders that stay meaningful forever.
2. **Money is a journal, not a counter.** Payments and refunds are append-only
   ledger entries; mistakes are corrected by new entries (void + re-record),
   never by editing. A refund is tagged to exactly one after-sales claim, so
   one refund can never settle two cases. Trade-off: more rows and explicit
   correction entries, in exchange for an auditable trail where "why is this
   number what it is" always has an answer.
3. **Idempotency keys on every write that matters.** Checkout and admin money
   actions take a client key; the same key replays the same result instead of
   creating a second order or a double payment. Trade-off: key discipline on
   the client, in exchange for safe retries on flaky networks and double
   clicks.
4. **Concurrency is handled with real locks, not hope.** Order rows lock
   during money movements; evening installation writes serialize on a
   Postgres advisory lock keyed by the Prague day, and a writer that can't get
   the lock gets a clean 409 instead of a hung request. Deadlines count from
   the customer's contact day on the Prague wall clock, DST changeover
   included (there is a test for that night).
5. **The catalog is an import target, not hand-typed rows.** A feed import
   plans in preview, applies in batches with per-batch journals and retries,
   records the origin of every written field, and dedupes by EAN → brand+MPN →
   SKU with admin decisions for the ambiguous cases. Trade-off: a lot of
   bookkeeping tables, in exchange for a catalog that can grow to thousands of
   cards without turning into soup.

## Honest boundaries

The demo says what it is on every page (`NEXT_PUBLIC_DEMO_MODE`): orders are
tests, no payment or delivery happens, and the shared category illustrations
are labeled as illustrations. Availability and lead times come from seeded demo
supplier offers; MPN/EAN codes are filled only where they were verified against
public product pages, and stay empty otherwise.
The local latency measurement in [`docs/perf-local.md`](perf-local.md) is a
sequential run against a 1 050-card copy — evidence the data model holds at
volume, not a production load test.

## See it

There is no public demo site yet — hosting is a separate step ([`docs/demo-deployment.md`](demo-deployment.md) holds the ready
release plan). Everything below comes from a local run; to see the shop live,
follow «Run locally» in the [README](../README.md).

- [`docs/screencast/nodra-overview.mp4`](screencast/nodra-overview.mp4) —
  a 22-second overview: storefront, checkout and one order through the back office
- [`docs/screenshots/storefront-home.png`](screenshots/storefront-home.png) —
  the English storefront with the demo banner
- [`docs/screenshots/storefront-mobile.png`](screenshots/storefront-mobile.png) —
  the 390 px catalog with facets
- [`docs/screenshots/admin-overview.png`](screenshots/admin-overview.png) —
  the admin dashboard: work queues, no supplier data exposed
- [`docs/screencast/demo-scenario.webm`](screencast/demo-scenario.webm) —
  an 11-second scripted walk: home → catalog → product → add to basket
