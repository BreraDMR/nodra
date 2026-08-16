# D08.1–D08.2 — installation bookings

Working spec for the installation-booking model in [`../development-plan.md`](../development-plan.md). Evening installation in Prague on top of an order: evening intervals with a duration, a limit per evening, the works list and the cancellation. Values marked **default** were chosen without the owner; prices and the works list stay **empty settings** until D00.4.

## What a booking is

An installation is booked on an existing order (`installation_booking`, FK to `shop_order`) — the customer buys parts, NODRA installs them in the evening. A booking is its own record; the order keeps its own state, the booking never touches lines, stock or the ledger.

Statuses: `planned` → `confirmed` → `done`; `planned` or `confirmed` → `cancelled` (terminal with `done`).

| Status | Meaning |
|---|---|
| `planned` | a window agreed, nothing checked yet |
| `confirmed` | compatibility and conditions checked with the customer (D08.2), the note says what |
| `done` | the work happened, the result note says how it went |
| `cancelled` | called off, the reason stored |

## Windows and the evening limit (D08.1)

- A window is a Prague evening interval `from`–`to`: one Prague calendar date, `from` at or after **17:00**, `to` at or before **21:00** (**default** evening end), `to` after `from`. Every wall-clock check is done in Europe/Prague, so the windows survive the DST changeover: 17:00–21:00 stays 17:00–21:00 on the night the clocks move.
- A booking has **two windows**. The **preliminary window** (`from`–`to`) is what was agreed with the customer when the booking was made. The **work window** (`workFrom`–`workTo`) is the confirmed actual work: set at `confirm` (by default from the preliminary window, the admin may adjust it), checked by the same rules. Capacity rules use the work window once confirmed, the preliminary one before.
- Two capacity rules on one Prague evening, over every booking still `planned` or `confirmed`:
  - **Count limit** (**default** 2, `maxPerEvening`): no more open bookings than the limit — a new booking, confirm or reschedule that would exceed it is a 409 `evening_full`.
  - **No overlap, room for the road**: the count limit alone does not keep two windows apart — two 17:00–19:00 bookings fit the count and still put the installer in two places at once. Two windows on the same evening must also be at least `travelMinutes` (**default** 30) apart: `new.to + travel <= other.from` or `other.to + travel <= new.from`, else 409 `window_taken`.
- A `cancelled` booking frees its evening slot; `done` blocks its evening no longer.

## Works and money (D08.2, D00.4)

- The works list is the setting `app.installation.works`: `{code, name, priceMinor|null}` entries. It ships **empty** — the owner fills it (D00.4); nothing is invented here. While it is empty the customer sees **no way to buy an installation** anywhere on the storefront: the purchase does not exist client-side until the owner fills the list and the owner-facing screen for it is agreed.
- A booking carries `works`: codes from that list. An unknown code is a 422 `unknown_work`; with an empty list no code is known, so an early booking carries no works — the list is agreed with the customer before confirmation.
- `priceMinor` is stored on the booking: the sum of the chosen works' prices. When any chosen work has no price yet, it is null — a booking without a price is fine, a pretended price is not. Later settings changes never move an old booking.
- Compatibility and conditions (D08.2) are checked before confirmation: `confirm` stores the note of what was checked. The money itself moves only through the order ledger as always.

## API (admin only, CSRF on writes)

`AdminInstallation`: `id`, `orderId`, `orderReference`, `customerName`, `status`, `from`, `to` (preliminary window), `workFrom`, `workTo` (null until confirmed), `durationMinutes` (of the window in force), `works` (codes), `workNames`, `priceMinor` (nullable), `note`, `compatibilityNote`, `resultNote`, `cancelledReason`, `createdAt`, `createdBy`, `confirmedAt`, `completedAt`, `cancelledAt`.

| Endpoint | Notes |
|---|---|
| `GET /api/admin/installations?status=&date=` | `{items, total}`, newest first; `date` is a Prague evening `YYYY-MM-DD` |
| `POST /api/admin/installations` | `{orderId, from, to, works?, note?}` → 201; 409 `evening_full`, `window_taken`, 422 bad window/works, 404 order |
| `POST /api/admin/installations/{id}/reschedule` | `{from, to}` on planned (moves the preliminary window) or confirmed (moves the work window) |
| `POST /api/admin/installations/{id}/confirm` | `{compatibilityNote, workFrom?, workTo?}` on planned; the work window defaults to the preliminary one |
| `POST /api/admin/installations/{id}/complete` | `{resultNote}` on confirmed |
| `POST /api/admin/installations/{id}/cancel` | `{reason}` on planned/confirmed |

Every write appends an order journal event (`installation_booked`, `installation_rescheduled`, `installation_confirmed`, `installation_completed`, `installation_cancelled`) with the admin's email. Installations never appear in any public response.

Settings (read through `GET /api/admin/settings`, gains `installation`): `works`, `eveningStart`, `eveningEnd`, `maxPerEvening`, `travelMinutes`, so the screen can explain why a window is refused.

## Tests to cover

Window rules (before 17:00, after 21:00, end before start, windows that leave the evening); the count limit on create and reschedule, a cancelled booking frees its evening slot; **two overlapping windows on the same evening refused even under the count limit**, the travel gap honoured (just under and just over `travelMinutes`); the DST changeover — a window on the night the clocks move is still judged by Prague wall time; the works list — unknown code 422, empty settings mean no works; the price — sum of priced works, null while a chosen work is unpriced; the whole lifecycle planned → confirmed (with the work window fixed, adjustable at confirm) → done; reschedule on planned and on confirmed; cancel from both open stages and terminality; date and status filters; settings exposure; journal entries carry the actor; nothing in public JSON; a through scenario on one order from checkout to a completed installation.
