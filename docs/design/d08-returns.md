# D08.4 — returns and warranty claims

Working spec for D08.4 in [`../development-plan.md`](../development-plan.md), on top of the D04/D05 order model. Goal from the plan: record returns and warranty cases separately from the original purchase and track their deadlines and money. Values marked **default** were chosen without the owner.

## What a claim is

Customers reach NODRA through the agreed channels (D00.3); the admin registers every after-sale case as a **claim** — its own record, linked to the order and, when the customer names the goods, to one order line. Two kinds:

- `return` — withdrawal from the contract (odstoupení od smlouvy), the customer backs out within **14 days of handover**.
- `warranty` — a warranty claim (reklamace), a defect within **24 months of handover**.

A claim never touches the order by itself: line states, stock, payments and points stay managed by the existing order actions (`return`, `replacement`, the ledger). The claim is the registry of the case: what was agreed, until when, and where the money stands. There is no customer-facing claim form (**default**; cases arrive by channel, the admin types them in).

Opening a claim requires the goods to have been handed over: the line's shipment for a line claim, the latest handed-over shipment for an order claim. Before handover a problem is a cancellation or a refused delivery, not an after-sale case.

## Lifecycle and deadlines

`open` → `waiting` → `accepted` → `resolved`; `rejected` from any open stage. `resolved` and `rejected` are terminal.

| Status | Meaning |
|---|---|
| `open` | registered, NODRA is deciding |
| `waiting` | waiting for the customer: goods to be sent back or an answer |
| `accepted` | NODRA acknowledged it; the refund, replacement or repair is being prepared |
| `rejected` | refused, with a reason |
| `resolved` | closed: refund recorded / replacement handed over / repair done |

Actions: `wait` (open → waiting, with a note), `accept` (open, waiting or accepted → accepted; an accepted → accepted call corrects the agreed amount and never moves a deadline), `reject` (with a reason), `resolve` (accepted → resolved, with the outcome).

Two dates live apart on purpose: **`contactedOn`** is the Prague calendar day the customer lodged the case (the withdrawal or the reklamacе), typed in by the admin — possibly days after the call; **`openedAt`** is when the admin entered the record. Deadlines count from `contactedOn`, per ČOI: the refund time for a withdrawal and the 30-day settle time for a warranty both run from the day the customer acted, not from the day NODRA got around to it.

Deadlines are **stored**, so later edits never shift them, and are Prague calendar dates (`YYYY-MM-DD`):

- `windowEnd` — the last day of the customer's window, counted from the handover date: `return` → handover + 14 days, `warranty` → handover + 24 months. `onTime` (computed) — the customer lodged the case on or before `windowEnd`. Entering late is allowed and changes nothing: `contactedOn` carries the real day.
- `dueAt` — fixed once when the claim is registered: `return` → `contactedOn` + 14 days (the law's refund time from the withdrawal), `warranty` → `contactedOn` + 30 days (**default**, goes into the reklamační řád, D00.7). Accepting again — even to lower the amount — never moves it. `overdue` (computed) — `dueAt` has passed and the claim is not closed yet (open, waiting or accepted).

## Money

- `refundAmountMinor` — the agreed refund, CZK minor, set at acceptance (optional for warranty, where the outcome may be a replacement or repair).
- Refund money itself moves only through the existing payment ledger on the order (append-only, voiding by correction as in D05.4). A refund recorded on the order may carry a **claim tag** (`claimId` on the ledger entry): the claim whose money it is. One entry settles one claim, so one refund can never cover two cases; a mistake is voided and recorded again with the right tag. Untagged refunds are the order's money — they cover no claim.
- Resolving with `refund` is refused (409 `refund_not_recorded`) until refunds tagged with this claim cover `refundAmountMinor`; voiding a tagged refund takes its amount away again. To settle for less, accept again with the lower amount first. The check and the status flip run in one transaction holding the order row lock — the same lock payment recording and voiding take — so simultaneous actions can never share one refund.
- `replacement` and `repair` close without a ledger check — no customer money moves. Points already reconcile through the ledger (D04.7); a claim adds nothing.

## API (admin only, CSRF on writes)

`AdminClaim`: `id`, `number` (`RC-` + 8 hex), `orderId`, `orderReference`, `customerName`, `itemId` (nullable), `productName`, `variantLabel`, `sku` (null on an order claim), `kind`, `status`, `note`, `refundAmountMinor` (nullable), `resolution` (nullable), `resolutionNote` (nullable), `openedAt`, `openedBy`, `contactedOn`, `handoverDate`, `windowEnd`, `onTime`, `dueAt`, `overdue`, `resolvedAt` (nullable).

| Endpoint | Notes |
|---|---|
| `GET /api/admin/claims?status=&kind=&q=` | `{items, total}`, newest first; `q` matches number, order reference, customer name and email; `status`, `kind` filter |
| `POST /api/admin/claims` | `{orderId, itemId?, kind, contactedOn, note?}` → 201; 409 `claim_open` when the line already has a non-closed claim; 422 unknown kind, missing order id, missing or future `contactedOn` |
| `GET /api/admin/claims/{id}` | 404 unknown |
| `POST /api/admin/claims/{id}/wait` | `{note?}`, open → waiting |
| `POST /api/admin/claims/{id}/accept` | `{refundAmountMinor?, note?}`, re-accept corrects the amount, never the deadline |
| `POST /api/admin/claims/{id}/reject` | `{reason}`, reason stored as the resolution note |
| `POST /api/admin/claims/{id}/resolve` | `{resolution, note?}`, `refund` checks the tagged refunds (409 `refund_not_recorded`) |

Errors: 404 for a foreign id, 409 on state transitions that don't hold, 422 in the `Problem` format with `violations` like everywhere. Every write appends an order journal event (`claim_opened`, `claim_waiting`, `claim_accepted`, `claim_rejected`, `claim_resolved`) with the admin's email. The admin order view (`GET /api/admin/orders/{id}`) gains a read-only `claims` array; its payment rows carry `claimId` on a tagged refund. Claims never appear in any public response.

Dashboard `GET /api/admin/dashboard` gains `claims: {open, overdue}` — the tile links to the Claims screen.

## Web (admin only)

- New admin screen **Claims**: list with `status`/`kind` filters and search; a register form that finds the order by reference (the orders search), then optionally picks a line and the day the customer contacted (defaults to today); actions per claim (wait, accept with the amount, reject, resolve with the outcome). Overdue and late `onTime: false` claims are visible at a glance.
- The order screen shows the order's claims (read-only) with a link to the Claims screen; the refund form picks the claim the money settles, and the ledger shows the tag on the entry.

## Tests to cover

Window maths for both kinds (14 days / 24 months from handover, order claim takes the latest handed-over shipment); opening without any handover refused; `onTime` from `contactedOn` on the last day vs the day after, entered late but lodged in time; the whole lifecycle open → waiting → accepted → resolved; accept again lowering the amount before resolve and leaving `dueAt` alone; resolve `refund` refused until refunds tagged with the claim cover the amount, one refund covering only its own claim, refunds tagged to no claim covering nothing, voided tagged refunds counting against, partial tagged refunds summing up; tag rules (refunds only, of this order, not on a closed claim); reject from every open stage and terminality of both ends; `wait` only from open; second claim on a line refused while one is open, allowed again after it closes; list filters and search; dashboard counts (overdue while still open too); journal entries carry the actor; claims absent from public JSON; the admin order view lists its claims; contract schemas match.
