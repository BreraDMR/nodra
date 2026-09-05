# D04 — order request, procurement, handover and payment on receipt

Working spec for the order stage. It replaces the demo checkout, which reserves supplier-derived stock and has one status for everything. Owner decisions it relies on: NODRA buys from a supplier **after** the customer orders, delivers only within Czechia, gets paid on receipt, and never shows the supplier. Values marked **default** were chosen without the owner and are settings, not constants.

**Owner decisions of 28 September 2026:** the order is always in **CZK** for every language (EUR stays a display reference, D06.7); personal delivery in Prague costs **149 Kč** below 500 Kč of goods and is free from 500 Kč; when the customer takes the order in parts, **every part is free** if the goods total is at least 500 Kč.

## Settings

Container parameters `app.delivery.*`, `app.payment.*`, `app.legal.*` in `api/config/services.yaml`, next to `app.pricing.*`:

| Setting | Value |
|---|---|
| `prague_fee_minor` | 14900 |
| `prague_free_from_minor` | 50000 (goods subtotal, haléře) |
| `carrier_fee_minor` | `null` — carrier delivery outside Prague is **off** until the owner sets a tariff (D00.2) |
| `carrier_cod_fee_minor` | `null` — cash on delivery via carrier is off with it |
| `pickup_note` | "Anděl, place and time agreed by message" (**default** until D00.3) |
| `handover_methods` | `cash`, `bank_transfer` (**default**; `card` only once there is a terminal) |
| `privacy_version` | `draft-2026-09` (**default** until the D00.7 texts exist) |

A method whose fee is `null` is never offered and a request for it is refused (D04.4: "unknown tariff → don't accept the configuration").

## Order and its independent states (D04.1)

The order keeps the idempotent checkout it has (Idempotency-Key + request hash + advisory lock). New orders are always `currency = CZK`; existing EUR demo orders keep their currency.

- **Order status** `status`: `requested` → `confirmed` → `completed`; `requested`/`confirmed` → `cancelled`. `requested` means NODRA still has to check the offer and agree price and date with the customer; `confirmed` means the customer agreed. `completed` is set automatically once every active line is delivered and the payment status is `paid`.
- **Line procurement** `order_item.procurement_status`: `from_stock` (covered by NODRA's own stock, reserved at checkout), `to_order`, `ordered` (with `supplier_reference`), `received`, `failed`; plus line `state`: `active`, `cancelled`, `returned`.
- **Shipments** (table `shipment`, one or more per order, each line belongs to one): `method` (`prague_personal`, `pickup_andel`, `carrier_cz`), `fee_minor` fixed at checkout, `status` `planned` → `scheduled` (agreed window `scheduled_from`/`scheduled_to`) → `handed_over`, or `refused` / `cancelled`. A shipment is *ready* when all its active lines are `from_stock` or `received` — computed, not stored.
- **Payment status** `payment_status`: `unpaid`, `partially_paid`, `paid`, `partially_refunded`, `refunded` — stored for queues, recomputed from the ledger on every write.
- **Journal** (table `order_event`): `order_id`, `type`, `data` (jsonb), `actor` (`customer`, admin email, `system`), `created_at`. Every transition, money entry, terms change and customer agreement writes one. Nothing is edited or deleted in it.

Totals: `subtotal_minor` = active lines; `shipping_minor` = non-cancelled shipment fees; `total_minor` = both. A later change can lower a fee but never raises one without a new customer agreement.

## Customer data and consent (D04.0)

Checkout adds: `phone` (required; `+` and 9–15 digits after removing spaces, a bare 9-digit number is taken as `+420`), `contactChannel` (`whatsapp`, `telegram`, `phone`; required, **default** preselected `whatsapp` in the form), `city`, `deliveryNote` (optional, up to 500 characters). Street address, city and postal code are required for delivery methods and ignored for pickup.

`consents.privacy` must be `true` — stored as `privacy_consented_at` and `privacy_text_version`. `consents.marketing` is optional, stored separately with its own time; unticked by default. For a signed-in customer the form is prefilled: name and email from the account, phone, channel and address from the account's latest order (`GET /api/account/me` returns them as `lastDelivery`).

## Own stock vs procurement (D04.2)

`product_variant.stock` now means goods NODRA physically holds. The demo values came from supplier snapshots, so a reversible migration moves each to zero with a `stock_movement` («Demo supplier snapshot cleared (D04)»); `down` restores the values from those rows and deletes them.

At checkout a line whose quantity is covered by own stock is `from_stock` and reserves it (stock movement, as today). Any other line is `to_order` and reserves nothing: the variant must not be `unavailable` (D02); `check_needed` is accepted and resolved by the manual confirmation. Public availability gains the own-stock case: a variant with stock ≥ 1 is `orderable` with lead time = handling days only. The `availableOnly` catalogue filter means `orderable`.

## Delivery methods and the 500 Kč threshold (D04.3)

- `pickup_andel` — free, always offered, shows `pickup_note`.
- `prague_personal` — postal codes `100 00`–`199 99`; fee 0 when the goods subtotal ≥ `prague_free_from_minor`, else `prague_fee_minor`. Any other postal code → refused with "outside Prague".
- `carrier_cz` — offered only when `carrier_fee_minor` is set.

The server computes every fee; the client never sends an amount except `expectedTotal`, used only to detect that the quote changed.

## Together or in parts (D04.4)

Lines are grouped into parts by their lead time: equal `leadTimeMaxDays` → the same part; `from_stock` lines use handling days; lines with unknown lead time (`check_needed`) form one "we'll confirm" part. `together` → one shipment, lead time = the longest. `split` → one shipment per part, offered only when there are at least two parts.

Fees per shipment: pickup 0; Prague personal — every part 0 when the goods subtotal ≥ 500 Kč, otherwise every part 149 Kč (**default** follows from the tariff); carrier — `carrier_fee_minor` per part.

`POST /api/checkout/quote` (no side effects) takes the basket, the method and the postal code and returns the lines with availability, the methods with `available`/`reason`, and both options (`together`, `split` or `null`) with shipments, fees, lead times and totals. `POST /api/checkout` takes the chosen `delivery: {method, fulfilment}` plus `expectedTotal`; if the recomputed total differs → 409 with the fresh quote; an unavailable method or `split` without two parts → 422.

## Payment on receipt (D04.5)

Table `payment` is an append-only ledger: `order_id`, optional `shipment_id`, `kind` (`payment`, `refund`), `method` (`cash`, `bank_transfer`, `card`, `carrier_cod`), `amount_minor` > 0, `recorded_at`, `recorded_by`, `note`, `idempotency_key` (unique). The admin records a payment when money is received at handover; carrier COD is recorded when the carrier pays it out. The same Idempotency-Key twice returns the first entry; a different body with the same key → 409. Paid more than the total → 422. Online prepayment is a later stage.

## Exceptions (D04.6)

- **Procurement failed**: the admin marks the line `failed` and then either cancels it or adds a replacement line; a replacement or any price/lead-time change requires `customerAgreedVia` (channel + note) in the journal. A model is never swapped without it.
- **Terms changed before confirmation** (price or lead time of a line): allowed while the order is `requested`; on a `confirmed` order it puts the order back to `requested` until the customer agrees again.
- **Cancellation** (order or single line) before handover: reserved own stock goes back; goods already `received` become own stock (stock movement with the order reference); payments already taken show as a refund due (payment status stays until the refund is recorded).
- **Refused at handover**: the shipment becomes `refused`; its received goods become own stock; the admin then cancels the order or re-plans a shipment.
- **Return after handover** (the 14-day withdrawal): the line becomes `returned`, the goods go to own stock, the refund is recorded in the ledger.

## Loyalty points (D04.7)

Points are earned once, when the order becomes `completed` (paid and all active lines delivered): 1 point per full 100 Kč of goods paid, as today. A refund writes a negative entry for the refunded goods amount, never below the points earned for the order; completing again never earns twice (unique earn entry per order). Spending points stays off.

## Admin

Minimal set to take a real order from request to paid handover on the local stand (queues and procurement batches are D05): orders list with order and payment status; order detail with customer, consents, lines (source, procurement status, supplier reference, cost snapshot), shipments, ledger and journal; actions — confirm with channel, change terms, mark line ordered / received / failed, cancel line or order, add replacement, schedule and hand over a shipment, mark refused, record payment or refund, return a line. Every action writes the journal; the old `PATCH /orders/{id}/status` is removed.

## Migration of the demo data

Existing orders: `placed` → `requested`, `processing` → `confirmed`, `shipped` → `confirmed` with its shipment `scheduled`, `completed` → `completed` with its shipment `handed_over`. Each gets one shipment with the old shipping amount (method `prague_personal` for a 1xx xx postal code, else `carrier_cz`) and lines `to_order`. A completed demo order gets one ledger entry `method = cash`, note «pre-D04 demo order», `recorded_by = system`, so its `paid` status has a source; the others stay `unpaid`. `down` restores the previous schema and statuses.

## Tests to cover

Phone and consent validation; prefill from the last order; Prague postal code range; 149 Kč below and 0 at exactly 500 Kč; split fees on both sides of 500 Kč; carrier refused while its fee is `null`; `split` refused with one part; `expectedTotal` mismatch → 409; the same Idempotency-Key twice → one order; own stock reserved only for `from_stock` lines; `unavailable` refused, `check_needed` accepted; every state transition allowed/refused; fee never raised by a later change; journal written by every action; payment ledger idempotency and overpayment; auto-complete and loyalty earned once; partial refund lowers points; cancelled and refused goods back to own stock; public receipt shows no cost, supplier or offer data; the demo-stock migration up/down restores the values.
