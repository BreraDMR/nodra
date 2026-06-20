# D03.2 — controlled feed import with a preview

Working spec for D03.2 in [`../development-plan.md`](../development-plan.md), built on the catalog model (D01) and the pricing engine (D02). Source research is [`../market/sources.md`](../market/sources.md): the first feed is the bike-components.de affiliate CSV through Awin (Create-a-Feed), Rose Bikes next, BIKE24 to be confirmed inside the Awin directory. Development can start against a fixture CSV with the documented Awin fields before the Awin program is approved; the live file is then a config value, not a code change.

## The import run (file → preview → apply)

- The source file is the Awin CSV (plain UTF-8, optional gzip, constant download URL per program). The admin **uploads the file** in the admin screen (**default**: upload only; stored URLs and scheduled fetching are D03.4).
- One run writes an `import_run` row: source, file name + sha256, row counts, result counts per report section, started/finished, admin email, per-row errors. This is the import-error log that D05.5 points to.
- Two steps, the reprice pattern again: `POST /api/admin/imports/preview` (multipart file) returns the report; `POST /api/admin/imports/apply` (run id) writes it. Apply refuses with 409 when the file (sha256) changed since the preview.
- Bounded and synchronous in D03.2: hard row cap per run (**default** 20 000 data rows), report lists truncated to 200 rows per section with complete counts. Chunked background processing is D03.4.

## Field mapping (Awin CSV → NODRA)

| Awin column | Where it lands |
|---|---|
| `product_id` | offer's supplier SKU; the re-import key for the same feed |
| `product_name`, `description` | name and description of a **new** product only; existing texts are never overwritten |
| `brand_name` | brand; unknown brand is created and reported (**default**) |
| `ean`, `mpn`, `model_number` | variant identity: GTIN and matching keys (EAN normalized, spaces/dashes allowed as in D01) |
| `colour`, `size` | variant attributes when the category schema defines them, else reported as unknown values |
| `price`, `currency` | offer price (the shop's retail price; the shop is the supplier) |
| `rrp_price` | variant RRP with source «feed <supplier>» and today's date, only when the variant has no admin-set RRP |
| `stock_quantity`, `in_stock`, `stock_status`, `number_available` | offer availability snapshot and quantity; `checkedAt` = now |
| `delivery_time` | offer lead time when a day range parses out of it, else null (the offer stays `check_needed`); unparseable values are reported |
| `delivery_cost` | supplier-level default inbound shipping, set once per supplier in admin — not per row |
| `image_url`, `large_image`, `alternate_image` | stored as source references for D07; the web never displays feed images (photo rights, sources.md) |
| `merchant_category`, `merchant_product_category_path` | category guess only; imports never create categories |
| `deep_link` | offer URL |
| `last_updated` | freshness baseline of the row |
| `base_price`, `upc`, `commission_group`, `parent_product_id` | ignored in D03.2 |

## Identity and matching (minimal; full dedup stays D03.3)

- A variant matches by EAN, else by (brand, MPN), else by (supplier, supplier SKU) for re-imports of the same feed.
- New products are created as `draft` with their variants; publication stays a manual admin action — the plan's «публикация после проверки».
- The same physical product matched through another supplier becomes an additional offer of the existing variant, never a second product.
- A row matching several existing variants, or an EAN claimed by two of our products, is a conflict: it goes to the conflicts section, nothing is written for it.

## The preview report

Counts on top, lists below; nothing is written by preview. Each list row links the product/variant and shows field, old → new.

- `newProducts` — product, brand, EAN/MPN, guessed category, feed price.
- `updates` — field-level diffs of existing variants (price, stock snapshot, RRP) and offer changes: new offer, price change, stock change.
- `conflicts` — the matching conflicts above, plus a row whose size/colour contradicts the existing variant with the same EAN.
- `unknowns` — unknown category (row parked, nothing written), unknown brand (created + listed), attribute keys/values outside the category schema (dropped from the row, listed), unparseable `delivery_time`, rows without EAN and MPN.
- `errors` — broken rows: missing name or price, unparseable numbers, duplicate `product_id` inside the file, encoding garbage. A bad row is skipped; the run continues.
- `cost` — per touched variant: feed price, currency, rate, landed cost, and the price the current pricing rules would suggest; totals include how many suggestions would be flagged `margin_too_low` — flagged, never auto-applied.

## Apply

- One transaction per run; rows are independent — one bad row never blocks the rest (the D01 «импорт не падает» rule).
- Every written field records its origin (the import run), the same discipline the pricing flows use; admin-cleared values are never refilled by a later run (see gaps).
- No deletion, ever: an offer missing from a newer feed just ages past the freshness window and the storefront says «уточним наличие и срок» (D02.4); an import never unpublishes or removes anything.
- The run is journaled with its counts; import errors surface in the admin — the hook D05.5 left open lands here.

## Gaps from D01 closed here

- «Cleared by admin» vs «not filled yet» gets a real nullable marker on category attribute definitions, as sketched on 28.09: the seeder stops refilling a category the admin emptied, and the feed importer obeys the same rule.
- A seeded product whose attributes fail the changed definitions no longer aborts the whole seed import: it becomes a per-row error in the run report while the rest of the file imports.

## Out of scope (later stages of D03 and beyond)

- Cross-supplier dedup beyond the key rules above, and a field-origin UI (D03.3).
- Scheduled refresh, stored feed URLs, chunking, retries (D03.4).
- AI verification of cards (D03.6).
- B2B wholesale feeds (Cyklomax, Paul Lange Ostrava, Jakubkolo XML — sources.md): they map to the same offer format once the accounts exist; their adapters join this pipeline one by one.

## Waiting for the owner

- bike-components.de as a procurement source at all — it is not in the concept's shop list yet; the feed's retail price would be the offer basis.
- Awin registration and program approval (a public site is needed for approval) — until then the importer runs on the fixture file.
- The defaults above: row cap 20 000, list truncation 200, brand creation on import, upload-only runs.

## Tests to cover

- Parsing: the fixture CSV with all documented fields, a gzipped file, BOM/encoding, missing optional columns.
- Matching: EAN with spaces/dashes, brand+MPN fallback, supplier-SKU re-import, two products claiming one EAN, one row matching two variants.
- Report: counts vs truncated lists for every section; unknown category/brand/attributes; unparseable price and `delivery_time`; duplicate `product_id`.
- Apply: drafts stay unpublished; offers update instead of duplicating; admin-set RRP and cleared values are never overwritten; an offer missing from the feed ages instead of being deleted; the run journal is written; apply refuses with 409 after the file changed.
- Seed importer: an emptied category stays empty; failing attributes become a row error, not an abort.
- Public JSON: no supplier, cost, feed URL or import data in any public response.
