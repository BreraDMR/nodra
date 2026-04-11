# D02 — cost, price, availability and lead time

Working spec for backlog items D02.1–D02.5 in [`../development-plan.md`](../development-plan.md). Owner decisions it relies on: procurement after the customer order, percentage markup, price slightly below the manufacturer's RRP, suppliers never shown to customers. Values marked **default** were chosen while the owner was away and wait for his confirmation (D00.5, D00.6).

## Cost of a variant

A variant's cost comes from its best **matched** supplier offer (`verification_status = matched`, `variant_id` set). An offer gains:

- `inbound_shipping_minor` — delivery to NODRA per unit, in the offer currency (0 when free);
- `fx_rate_czk` and `fx_rate_date` — CZK per offer-currency unit as integer millionths, with the date of the rate (1.0 for CZK).

`landed_cost_czk = round((price_minor + inbound_shipping_minor) * fx_rate_czk / 1_000_000)`, in haléře.

"Best" = the fresh matched offer with the lowest landed cost; ties → shorter maximum lead time. **Fresh** = `checked_at` not older than 7 days (**default**, one setting).

## Pricing rules

Table `pricing_rule`: optional `category_id` (null = default rule), `min_cost_czk_minor`, optional `max_cost_czk_minor`, `markup_bp` (basis points, 3500 = 35 %), `active`. The rule for a variant is the one on the nearest category up the tree whose cost band contains the landed cost; otherwise the default rule.

**Defaults seeded** (owner to tune): all categories — cost < 300 Kč → 60 %, 300–1000 Kč → 40 %, 1000–3000 Kč → 30 %, ≥ 3000 Kč → 20 %.

Suggested CZK price = landed cost × (1 + markup), rounded **up** to whole 10 Kč. If the variant has an RRP and the suggestion is above `RRP × 0.97` (**default**, owner's "slightly below RRP"), use `RRP × 0.97` rounded down to 10 Kč — but never below `landed cost × 1.10` (minimum margin 10 %, **default** for D00.6). A variant whose suggestion can't meet the minimum margin is flagged `margin_too_low` and never auto-published or auto-repriced.

EUR price = CZK price / `display_rate_czk_per_eur` (one setting, **default** 25.0), rounded to 0.10 €, until D06.7 decouples display currency from language.

## RRP and own price history

Variant fields `rrp_minor`, `rrp_currency`, `rrp_source` (URL or short text), `rrp_checked_at`. RRP is admin-only reference data: it is never rendered as a previous or struck-through NODRA price (ČOI rules on discounts).

Table `price_change`: `variant_id`, old/new CZK and EUR, `reason` (`manual`, `reprice`, `import`), admin email or `system`, time. Written by every price change, including existing admin variant/product edits. It is the basis for any future discount claims.

## Market reference price

The competitor survey ([`../market/competitors.md`](../market/competitors.md)) showed Czech shops selling common parts 30–66 % below RRP, so "slightly below RRP" alone can leave NODRA far above the street price. A variant can therefore also carry `market_price_minor` (CZK), `market_price_source` and `market_checked_at` — the lowest price a Czech customer would realistically pay elsewhere, entered by the admin. It is admin-only, like RRP. The pricing panel and the reprice preview show NODRA's price against it, and a variant priced more than 15 % above it (**default**) is flagged `above_market`. The flag only informs; it doesn't block publishing, because installation and evening delivery can justify the gap.

## Availability and lead time (public)

Per variant, computed server-side:

| State | Condition | Customer sees |
|---|---|---|
| `orderable` | a fresh matched offer with reported quantity null or > 0 | lead time `min..max` days |
| `check_needed` | only stale, snapshot or unmatched offers | "we'll confirm availability and date" |
| `unavailable` | no offers, or all rejected / quantity 0 | not orderable |

Lead time = offer `lead_time_min/max_days` + handling 1 day (**default** setting). Unknown offer lead time → `check_needed`.

Owned stock (`product_variant.stock`) stays a separate concept for goods physically held by NODRA; the demo checkout still reserves it until D04 replaces checkout. Public responses gain `availability: {status, leadTimeMinDays, leadTimeMaxDays}` per variant and a card-level best status; they never include cost, supplier, seller, offer URL, fx or markup.

## Order snapshot (D02.5)

`order_item` gains nullable `lead_time_min_days`, `lead_time_max_days`, `availability_status`, `supplier_offer_id` and `unit_cost_czk_minor`, filled at checkout from the same calculation, so later catalogue or offer changes never alter an existing order. Cost and offer are admin-only.

## Admin

- Pricing rules list and editor.
- Variant pricing panel: offer → landed cost breakdown, rule used, suggested price, margin %, RRP, `Apply suggestion` (writes history), manual override (also history).
- Reprice preview for a category or the whole catalogue: rows with current vs suggested price and margin, then apply selected; flagged rows can't be applied.
- Price history per variant.
- Dashboard tile: variants with `margin_too_low`, `above_market` or `check_needed`.

## Tests to cover

Rounding and fx; `above_market` threshold; rule precedence (nearest category, cost band, default); RRP cap and minimum-margin floor; flagged variants excluded from reprice; history written on every change; freshness boundary; lead time with handling days; unknown lead time → `check_needed`; order snapshot unchanged after offer edits; no cost/supplier fields in any public JSON.
