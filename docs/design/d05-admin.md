# D05 — the admin for daily work

Working spec for D05.1–D05.5 in [`../development-plan.md`](../development-plan.md), built on the D04 order model ([`d04-order.md`](d04-order.md)). Goal from the plan: the owner runs every order through the admin without touching the database and never loses a debt, a delay or a purchase. Values marked **default** were chosen without the owner.

## Queues (D05.1)

Computed on the server; one order can sit in several. Each queue is a filter of the orders list (`queue=`) and a count on the dashboard (`GET /api/admin/queues`).

| Queue | An order is in it when |
|---|---|
| `review` | status `requested` |
| `problem` | an active line is `failed` (the customer has to be contacted) |
| `to_purchase` | status `confirmed` and an active line is `to_order` |
| `waiting` | an active line is `ordered` |
| `delayed` | an `ordered` line is past its promised date (below) |
| `to_schedule` | a shipment is ready (every active line `from_stock` or `received`) and still `planned` |
| `delivering` | a shipment is `scheduled` |
| `unpaid` | a shipment was handed over and `amountDue` > 0 |
| `refund` | `refundDue` > 0 |

**Promised date** of a line = the day the order was confirmed + the line's snapshot `leadTimeMaxDays`. Lines without a lead time are never `delayed`. The line and the order carry a `delayed` flag in admin responses.

## Purchases grouped by supplier (D05.3)

Table `purchase`: `supplier` (the offer's supplier code), optional `seller`, `reference` (the supplier's order number), `currency`, `fx_rate_czk` + `fx_rate_date` (as on offers, 1 000 000 for CZK), `inbound_shipping_minor` for the whole purchase, `status` `ordered` → `received` or `cancelled`, `ordered_at`, `received_at`, `note`, `created_by`. Table `purchase_line`: `purchase_id`, `order_item_id`, `quantity`, `unit_price_minor` (purchase currency), `allocated_shipping_minor`, `unit_cost_czk_minor` (actual).

- **To purchase screen:** `to_order` lines of confirmed orders grouped by the supplier of their snapshot offer; lines without an offer form a «no source» group. Each line shows the order, variant, quantity, the offer's link, seller and price, and the snapshot cost.
- **Create purchase** from selected lines of one supplier: seller, reference, currency, rate, per-line unit price (prefilled from the offer), inbound shipping total. The lines become `ordered` with `supplier_reference = reference`. A human places the order on the supplier's site; the system only records it.
- **Allocation:** inbound shipping is split across lines in proportion to line value (unit price × quantity), in minor units; the rounding remainder goes to the largest line. Actual unit cost CZK = round((unit price + allocated shipping / quantity) × rate). Integer maths only.
- **Receive purchase** marks all its still-`ordered` lines `received`; single lines can still be received one by one. **Cancel purchase** (before receipt, with a reason) puts its lines back to `to_order`.
- The per-line «mark ordered» action from D04 stays for a single quick line; it records the reference without a purchase and without an actual cost.

## Order economics (D05.2)

Admin order view per line: exact variant, source offer (supplier, seller, link, price, currency), snapshot unit cost, actual unit cost (from its purchase line, or null), purchase reference, promised date, delayed flag. Per order: goods revenue (active lines), shipping charged, cost (actual where known, else snapshot), **expected result** = goods revenue − cost, and its margin %, plus `costComplete` (every active line has an actual cost). All admin-only.

## Parts, handover windows, payments and corrections (D05.4)

Every correction needs a `reason` and writes the journal with the admin's email.

- **Move a line** to another not-handed-over shipment of the order, or to a new part. The owner splits the order for his own reasons, so a new part costs the customer nothing (**default**: fee 0). Moving never raises a fee; a shipment left empty is cancelled as in D04.
- **Reschedule** a `scheduled` shipment to a new window.
- **Undo received** on a line (back to `ordered`) while its shipment isn't handed over, for a mistaken click.
- **Void a payment or refund entry:** the ledger stays append-only — voiding writes a `correction` entry that cancels the original's amount and points at it; payment status is recomputed; an entry can be voided once.

## Search, delays, roles (D05.5)

- Orders list `q`: reference, customer name, email, phone digits, supplier reference.
- Delays surface as the `delayed` queue and a dashboard tile; outbound notifications (email, messenger) wait for the transactional email of D09.
- Import errors have no log yet; they belong to D03.
- Roles: one owner-admin for now (**default**), no role system.

## Gaps from D04 closed here

- Quote `methods[]` carries `freeFromMinor` for Prague delivery; a quote-level `method_unavailable` has its `reason`.
- The public receipt includes `contactChannel`.
- The pickup note is a per-locale setting (cs, de, en).
- Admin gets the accepted payment methods (`GET /api/admin/settings`, with delivery fees and the privacy text version).
- The migrated completed demo order gets `handedOverAt` = its creation time and its lines `received` (reversible migration).
- Dashboard: the low-stock list is renamed to own stock and lists variants with own stock only; the `checkNeeded` pricing alert skips variants that have own stock.
- A wrong admin password answers in the `Problem` format like every other error (D01 tail).

## Tests to cover

Every queue on both sides of its condition; the promised-date boundary (exactly on the day vs a day later); allocation with remainders and three lines; actual cost with an EUR purchase; receive and cancel purchase with the lines' states; purchase lines from two suppliers refused; move line to a new part with fee 0 and never a raised fee; reschedule; undo received refused after handover; voiding a payment twice refused and the status recomputed; search by each field; expected result with and without actual costs; the D04 gaps above; no purchase or cost data in any public JSON.
