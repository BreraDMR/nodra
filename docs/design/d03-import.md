# D03 — controlled feed import: preview (D03.2), dedup and origins (D03.3), chunked refresh (D03.4)

Working spec for the import stage of [`../development-plan.md`](../development-plan.md), built on the catalog model (D01) and the pricing engine (D02). Source research is [`../market/sources.md`](../market/sources.md): the first feed is the bike-components.de affiliate CSV through Awin (Create-a-Feed), Rose Bikes next, BIKE24 to be confirmed inside the Awin directory. Development can start against a fixture CSV with the documented Awin fields before the Awin program is approved; the live file is then a config value, not a code change. D03.2 is done and live; the D03.3 and D03.4 sections below are the working spec for the next two stages.

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

## Identity and matching (as D03.2 shipped it; D03.3 extends it below)

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

## D03.3 — dedup between suppliers, manual tools, field origins

The D03.2 matching finds the same variant again on a re-import of the same feed. D03.3 closes the cross-supplier case: the same physical product from another feed, or from the seed, becomes another offer of the existing variant — never a second product. Disputed matches stay disputed: they land in `conflicts` and nothing merges by itself.

### Matching by characteristics

- The matching order becomes: the supplier's saved **binding** (see below), then EAN, then brand + MPN, then the supplier's existing offer by SKU, then **characteristics**.
- The characteristics key fires only when the row carries `colour` or `size`. Candidates are variants whose product sits in the category the guess picked for the row, carries the same brand, and whose effective colour/size (variant override, else product attribute, else the colour/size columns) equals the row's — trimmed, case-insensitive.
- Exactly one candidate → the row is matched and becomes an offer of that variant like any other match. Several candidates → a conflict («matches N variants by its characteristics»). None → the new-product path as before. A row with no colour/size and no EAN/MPN can never be identified and stays a new draft.
- The existing contradiction rule (row says size L, the variant with the same EAN carries M) still fires first: a contradicting row is a conflict even when a characteristics match would exist.

### Manual binding of a feed row to an existing variant

- Why: the 80 demo cards have neither EAN nor MPN, so a real feed can never match them and would park duplicate drafts next to them. EANs are never invented (spec rule); the admin binds the row to the variant instead.
- New table `import_feed_binding`: supplier + supplier SKU → variant, with the author and the date, unique per supplier + SKU. The match starts from it, so the binding is remembered for every following run of that feed.
- API (admin, CSRF on writes): `GET /api/admin/imports/bindings?variantId=…` (list of a variant, and a paged list without the filter), `POST /api/admin/imports/bindings` `{supplier, supplierSku, variantId}`, `DELETE /api/admin/imports/bindings/{id}`.
- The report's `conflicts` rows gain the row's supplier SKU (`sku`), so the admin can bind straight from a conflict. The variant card in the admin lists its bindings and accepts a new one.
- A binding is a claim, not a write: the bound row becomes an offer only through the normal preview → apply path.

### Merge two products by hand

- `POST /api/admin/products/{id}/merge` `{targetId}`: everything of the source product moves to the target; nothing is deleted.
  - A source variant with exactly one counterpart in the target (by EAN, else brand + MPN, else effective colour/size): its offers are re-pointed to the counterpart, identity the counterpart lacks (EAN, MPN, attributes) is copied over with origin rows, and the emptied source variant is deactivated — its history stays.
  - A source variant with no counterpart, or with several, moves to the target as a whole (its offers follow); offers without a variant follow to the target product.
  - The source product ends `archived` — a new status next to draft/published: invisible on the storefront, still visible in the admin list with its history, reversible by hand.
- The merge journals itself as an import run (source `merge`) with the per-variant decisions in its report; every copied identity field gets an origin row.
- Refuses with 409 when both ids are equal or the target is archived.

### Field origins in the admin

- `import_field_origin` gains a nullable run and a nullable admin email, so three kinds of writes share one table: **seed** (run of source `seed`), **feed** (run of source `awin_csv`, shown as «feed <supplier> + run») and **admin** (no run, the admin's email).
- The seeder records origins for the cards and variants it creates (it only fills blanks on existing rows, as before). The admin write paths — product form, variant form, offer form — record origins for the fields they set, the same field names the import uses.
- `GET /api/admin/origins?type=product|variant|offer&ids=…` returns, per entity id, the latest origin of each written field: kind (`seed | feed | admin`), run id and source, supplier, admin email, `writtenAt`.
- The product editor and the variant editor in the admin show a «Field origins» block for the card being edited: field → where the value came from and when it was written. Cards written before the journal existed simply have no rows — shown as unknown, never guessed.

### D03.2 tails folded in here (browser check, 29.09)

- The updates section renders money fields as money with the feed currency (update rows now carry `currency`) — no more raw cents like 5990 → 5490.
- The apply result counts the drafts it wrote without a calculated price (`draftsWithoutPrice`), the apply message in the admin says so, and the products list shows «price not calculated» instead of 0,00 € for variants whose price was never set. Prices are never invented by the import.
- The third tail — feed rows that cannot match the demo cards — is the manual binding above. Where real EANs for the demo catalogue should come from is a question for the owner, not for the import.

### Waiting for the owner (D03.3)

- Where real EANs for the 80 demo cards should come from (supplier data, buying sheets, a paid catalog) — until then the cards stay without EAN/MPN and real feeds match them only through a manual binding.
- The defaults: characteristics matching only with colour/size present and a unique candidate; merge archives the source instead of deleting; identity copies overwrite nothing the target already has.

### Tests to cover (D03.3)

- Matching: a colour/size row matches the one variant with the same characteristics and adds an offer; two such variants → conflict; a row without colour/size and without EAN/MPN stays a new draft; a contradicting size still wins as a conflict.
- Binding: a bound supplier SKU matches before everything else on the next preview and applies as an offer of the bound variant; unknown variant or supplier → 422; the variant's binding list shows on the card; unbinding restores the old behaviour.
- Merge: every offer of the source survives on the target; identity copies carry origin rows; a variant without a counterpart moves; the source ends archived and leaves the storefront; merging into itself → 409; the merge run is in the journal.
- Origins: a seed import records seed origins; an admin edit records `admin` origins with the email; the origins endpoint answers the latest write per field; no origin data in any public JSON.
- Tails: update rows carry the currency; the apply result counts price-less drafts.

## D03.4 — chunked feed refresh in the background

D03.2 applies a whole uploaded file in one synchronous transaction. D03.4 adds the standing loop: the stored feed of a supplier is refreshed row-batch by row-batch in the background, with limits, retries and an error journal. There is no message broker in the project, so the background mechanism is what the project already has: Doctrine tables plus console commands. No cron is installed and no worker daemon runs on the stand.

### What runs

- The supplier's feed address is stored per supplier (`import_supplier_setting.feed_url`, set on the admin Import screen next to the inbound shipping). It may be an `http(s)://` URL or a local path — tests use local files only, and the tests never reach the internet.
- The console command `app:import:feed [supplier]` is the whole scheduler: hang it on cron (for example every 15 minutes); without an argument it walks every supplier that has a feed URL. One execution per supplier:
  1. re-queue its interrupted batches (status `running` for longer than the stale window) and its failed batches that have attempts left;
  2. unless `--force` or the frequency limit allows it, download the feed (bounded: timeout, size cap), parse it, match and plan the whole file exactly once, create the run with the same report structure as a preview and split the planned writes into batches of N rows;
  3. execute the pending batches one by one, each in its own transaction with its own journal row.
- New table `import_batch`: run, supplier, batch number, row range, status `pending|running|done|failed`, attempts, max attempts, the stored batch plan (JSON), the error. A batch that throws is journaled failed with the message and the run continues with the next batch — rows are independent, and a supplier's failure deletes nothing (the import never deletes anything anyway; offers just age).
- The batch plan is stored with the batch, so a retry replays exactly those writes without the file; if a referenced row has disappeared meanwhile, the batch fails with a clear error and nothing partial is written.
- Run statuses: a download or parse failure ends the run `failed` with zero batches and nothing written; a processed run ends `applied`, and its counts carry how many batches failed (`batchesFailed`) with their errors in the run's error list.
- Frequency limit: a supplier is refreshed at most once per `app.import.min_feed_interval` minutes (**default** 60); `--force` overrides. Past that, the next execution starts a new run.
- Retries: every execution retries failed batches while `attempts < max_attempts` (**default** 3 attempts per batch). Past that only a human moves: `POST /api/admin/imports/batches/{id}/retry` re-executes exactly that stored batch plan and returns its state — the manual restart of a failed batch.
- The admin Import screen gains a «Feed refresh» section: per supplier the feed URL and inbound shipping (saved together), the recent feed runs with their batch states, and a retry button on a failed batch. The error journal of D05.5's hook stays the run journal + batch errors.
- **Defaults**: 500 rows per batch, 20 000 rows per run (as D03.2), download timeout 30 s and size cap 50 MB, refresh interval 60 min per supplier, 3 attempts per batch, a `running` batch is stale after 30 min.

### Waiting for the owner (D03.4)

- The cron entry on the real host (only the command exists here), the real feed URLs and the Awin approval — until then the command runs against fixture files by hand.
- Whether the admin should also get a synchronous «refresh now» button before a real schedule exists; for now the refresh happens through the command or a manual batch retry.

### Tests to cover (D03.4)

- Loader: a local file feed and a test HTTP stream parse the same; a missing or unreadable source ends the run `failed` and writes nothing.
- Batching: a planned run splits into batches of the configured size; each batch writes in its own transaction; the run's counts aggregate the batches.
- Failure: one bad batch does not stop the others; it is journaled failed with the message and attempts = 1; the run reports partial success; nothing is deleted or emptied.
- Retry: a failed batch re-executed after the underlying problem is fixed ends done; attempts grow; past the max attempts only the manual endpoint runs it; the endpoint replays the stored plan.
- Frequency limit: a second execution inside the interval is refused; `--force` and an elapsed interval allow it.
- Settings: feed URL and inbound shipping save per supplier, show in the admin; an unknown supplier is a 422.
- Public JSON stays clean: no feed URLs, batches, retries or origins anywhere public.

## Out of scope (later stages of D03 and beyond)

- AI verification of cards (D03.6).
- A synchronous «refresh now» button in the admin and a real scheduler inside the app (D03.4 keeps the console command as the only entry point until the owner asks for more).
- B2B wholesale feeds (Cyklomax, Paul Lange Ostrava, Jakubkolo XML — sources.md): they map to the same offer format once the accounts exist; their adapters join this pipeline one by one.

## Waiting for the owner (D03.2)

- bike-components.de as a procurement source at all — it is not in the concept's shop list yet; the feed's retail price would be the offer basis.
- Awin registration and program approval (a public site is needed for approval) — until then the importer runs on the fixture file.
- The defaults above: row cap 20 000, list truncation 200, brand creation on import, upload-only runs.

## Tests to cover (D03.2)

- Parsing: the fixture CSV with all documented fields, a gzipped file, BOM/encoding, missing optional columns.
- Matching: EAN with spaces/dashes, brand+MPN fallback, supplier-SKU re-import, two products claiming one EAN, one row matching two variants.
- Report: counts vs truncated lists for every section; unknown category/brand/attributes; unparseable price and `delivery_time`; duplicate `product_id`.
- Apply: drafts stay unpublished; offers update instead of duplicating; admin-set RRP and cleared values are never overwritten; an offer missing from the feed ages instead of being deleted; the run journal is written; apply refuses with 409 after the file changed.
- Seed importer: an emptied category stays empty; failing attributes become a row error, not an abort.
- Public JSON: no supplier, cost, feed URL or import data in any public response.
