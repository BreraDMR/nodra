# Local performance measurement

The goal: on a separate copy of the database with 1000+ synthetic product cards, measure the latency of the catalog, search, facets, product page and checkout, look at `EXPLAIN` for the heaviest queries, and state plainly that this measurement is **not** a production check.

## Limits (read this first)

This is a local Mac setup, not a production-like environment:

- a single `php -S` worker (single-threaded), and the measurement is **sequential**: no concurrency and no load, so it is not a load test;
- the Next.js storefront was not measured, only the JSON API;
- checkout was measured up to placing the order (pay on delivery), without admin endpoints or money movements;
- the database is warm, the prod container cache is warmed up, and the data is small for Postgres;
- a real load run belongs on the chosen hosting, once there is one, with the target traffic.

The conclusions are good for one thing only: "where are the bottlenecks at 1000+ cards on the current architecture". They are not good for planning server capacity.

## How to reproduce

```bash
# 1. database (Postgres container)
docker compose -f api/compose.yaml up -d database   # from the repo root; port 55432

# 2. back up the stand and make an app_perf copy from the dump (the stand is not touched)
./scripts/backup.sh
COPY_DB=app_perf ./scripts/restore.sh "$(ls -t api/var/backups/app-*.sql | head -1)"

# 3. synthetic data: 1050 cards (perf-*, PERF-*), only in app_perf — double safety catch
DATABASE_URL='postgresql://app:!ChangeMe!@127.0.0.1:55432/app_perf?serverVersion=16&charset=utf8' \
  php scripts/perf-populate.php 1050

# 4. API on 8001 in prod mode on the copy — the EGPCS flag is required, see "Gotchas"
cd api && DATABASE_URL='postgresql://app:!ChangeMe!@127.0.0.1:55432/app_perf?serverVersion=16&charset=utf8' \
  APP_ENV=prod APP_DEBUG=0 php -d variables_order=EGPCS -S 127.0.0.1:8001 -t public public/index.php

# 5. measure (within the rate limits: 240/min catalog, 30/min checkout — 20 iterations per GET scenario and 8 per write by default)
./scripts/perf-run.sh 20

# 6. afterwards: the copy is dropped, everything is shut down
docker exec api-database-1 psql -U app -d postgres -c 'DROP DATABASE app_perf'
```

The EXPLAINs were run through `docker exec -i api-database-1 psql -U app -d app_perf` on queries from `CatalogService` and `AvailabilityService`/`PricingData`, with literals from a real page (category ids, the page uuids, attribute keys).

## Environment

Mac (Apple Silicon), PHP 8.5.10 with opcache (checked: `opcache_get_status()` is active on the cli-server), Postgres 16 in Docker, Symfony kernel in prod mode (`APP_ENV=prod APP_DEBUG=0`). Data in the copy: 1149 products (1135 published, 1050 of them synthetic `perf-*`), 2212 variants, 1180 supplier offers. The synthetic data: 1-3 variants per card, half the variants have a `matched` offer with lead times, every card has category attributes and valid EAN/MPN, and only every 7th product has its own stock.

## Results (ms, sequential requests)

| Scenario | p50 | p95 | max |
|---|---|---|---|
| Category tree (`GET /api/categories`) | 10.8 | 11.4 | 14.3 |
| Catalog, 1st page, featured sort | 14.1 | 14.8 | 15.1 |
| Catalog, 1st page, `sort=price_asc` (global) | 13.2 | 13.5 | 13.8 |
| Catalog, `components` subtree (~1050 cards) | 13.0 | 13.9 | 14.1 |
| Catalog, subtree, last page (offset ~1050) | 12.9 | 13.2 | 13.3 |
| Search by name (`q=Perf 50`) | 14.3 | 15.5 | 15.7 |
| Search by MPN (`q=PFM-000500`) | 14.2 | 19.3 | 19.9 |
| Facets of the `components` subtree | 10.3 | 10.7 | 12.6 |
| Product page | 12.1 | 12.4 | 12.5 |
| Checkout: quote (1 item) | 10.0 | 10.1 | 10.2 |
| Checkout: placing an order | 19.8 | 19.9 | 20.2 |

The 9 orders from the run were written into the `app_perf` copy (`ND-…` references, buyer `Perf Runner`) and disappeared together with it. The stand (`app`) was not touched: it still had its 8 orders and 0 synthetic cards (checked with counters before and after).

## EXPLAIN of the heaviest queries (ANALYZE + BUFFERS)

| Query | Time | Plan |
|---|---|---|
| Catalog listing (GROUP BY over 1135 products, top-12) | 2.2 ms | Seq Scan product+variant → HashAggregate → Sort; the planner did not pick the `(featured_rank, slug)` index here — at this size a full pass is the more honest choice |
| Catalog counter (`COUNT(*) published`) | 0.1 ms | **Index Only Scan** on `idx_product_browse`, so that index works |
| ILIKE search by name/MPN + EXISTS over variants | 1.3-1.4 ms | Seq Scan with recheck; without pg_trgm this is a full pass, which doesn't hurt at 1000+ cards |
| Facets: brands of the subtree | 0.4 ms | Seq Scan, group by brand |
| **Facets: attribute aggregation (jsonb)** | **4.9 ms** | The heaviest query: `jsonb_each_text` over 1669 variants → 3344 rows → Sort + GroupAggregate |
| Availability: variants of a 12-card page | 0.03 ms | Index Scan on `product_variant(product_id)` |
| Availability: offers of the page | 0.08 ms | Seq Scan (the table is small, the planner is right) |
| Checkout: sourcing of one product | 0.01 ms | Index Scan on `idx_supplier_offer_product` |

## Conclusions

1. **At 1000+ cards SQL is not the bottleneck.** The most expensive query is the jsonb facets (4.9 ms), the listing with an aggregate over all variants takes 2.2 ms, and everything else is fractions of a millisecond.
2. **The Symfony framework costs ~8-10 ms per request**: the cheapest API calls (category tree, quote) have a p50 of ~10 ms, so almost all of an HTTP request's time is not the database. It is worth optimizing the framework layer (a real server, reverse proxy, cache), not the queries.
3. **The earlier indexes are confirmed at volume**: the catalog counter runs as an Index Only Scan and the offer lookup goes through an index.
4. **Candidates for later (not now):** cache the jsonb facets when the catalog grows towards 10k+; use pg_trgm for ILIKE search once search becomes a noticeable share of latency; revisit deep OFFSET pages as the card count grows (currently 12.9 ms, no problem).
5. The rate limits (240/min catalog, 30/min checkout) don't get in the way of the measurement on the local stand with a budget of 20 iterations per scenario.

## Gotchas (for the next measurement)

- **`php -S` does not pass real environment variables into the HTTP context** (PHP's `variables_order` has no `E`): dotenv quietly substituted `DATABASE_URL` from `.env`, and the server on 8001 looked at the **stand's** database even though the process environment pointed at the copy. This was worked out from the catalog counters (85 ≠ 1135) and a probe with a manual kernel boot. The fix is the `php -d variables_order=EGPCS` flag (see the command above). For the same reason step 3 (populate through the CLI kernel) works without the flag, because the CLI context does receive the variables.
- `docker compose -f api/compose.yaml up` from the root does **not** pick up `compose.override.yaml` with the port 55432 mapping, so the container comes up without the port. Either run `docker compose up` from `api/`, or pass two `-f` flags.
- The rate limits live in a file cache; between runs they are reset by deleting `api/var/cache/prod/pools` or by waiting a minute.
