# How NODRA works: seven decisions the shop is built on

A plain-language walkthrough of the key decisions behind the shop. You don't need to
read code to follow it; the file and test references are there so an engineer can find
the spot right away, and so the claims can be backed up in an interview.

---

## 1. An order remembers the terms at the time of purchase

**Why.** The storefront shows the lead time and source of a product "right now", but the
actual purchase happens later: the supplier price changes, the offer disappears or goes
stale. If an order points at "current" data, a week later nobody can say what the customer
actually bought or what we promised them.

**How it works.** At checkout every order line gets a snapshot of the terms: availability
status, promised lead time (min/max days), a link to the supplier offer and the purchase
cost in crowns. From then on the order lives with its own numbers; the storefront can change
as much as it likes. Order economics are computed from two numbers: the purchase cost from
the snapshot and the actual one, once it is known.

**Where in the code.** `api/src/Checkout/CheckoutService.php`, function `place()` takes the
snapshot at checkout; `api/src/Entity/OrderItem.php` holds the order line fields (lead times,
offer, purchase cost); `api/src/Order/OrderEconomics.php` computes margin from the snapshot
and from the actual cost.

**What backs it up.** `api/tests/Checkout/CheckoutSourcingTest.php`, test
`testOrderKeepsTheSourcingOfTheMomentOfCheckout`: after checkout the storefront data is
changed and the test checks the order still holds the snapshot. Next to it,
`testUnavailableVariantIsRefusedAndNothingIsReserved` makes sure an unavailable product
can't even be ordered.

**Interview question.** "We show a 3-5 day lead time, the customer places an order, and the
next day the supplier raises the price. What does the customer see, and what do you see?"
Answer: the customer sees the promise as it was at order time (lead time and price in the
order don't change); the admin sees both the snapshot and the fresh purchase price side by
side, and the difference shows up as the line margin.

---

## 2. Money is a ledger, not a counter: mistakes are fixed by adding entries, not deleting

**Why.** Keeping "paid 990 Kč" in a single table cell is convenient until real life happens:
a payment is taken twice, the cashier typed the wrong amount, the customer cancelled and got
a refund. If the amount is an editable field, the history is lost and there is nothing to
point at in a dispute.

**How it works.** Every movement of money is its own entry (a payment or a refund, the
method, who recorded it, a comment). The "paid" total is the sum of entries and nobody edits
it directly. A mistake is fixed with a correction and a reason: the entry is marked void, and
the order's event journal next to it records who did it, when and why. An entry cannot be
deleted by any means.

**Where in the code.** `api/src/Entity/Payment.php` is the money ledger entry;
`api/src/Order/OrderCorrections.php` is the void correction; `api/src/Order/OrderJournal.php`
and `api/src/Entity/OrderEvent.php` make up the event journal with reasons.

**What backs it up.** `api/tests/Order/OrderCorrectionsTest.php`, test
`testVoidingAnEntryTwiceIsRefusedAndThePaymentStatusFollows`: an entry can't be voided twice,
and the payment status recalculates by itself;
`testVoidsOnACompletedOrderKeepThePointsInStepWithTheMoney`: after a void, the customer's
loyalty points stay consistent with the money.

**Interview question.** "The cashier recorded a payment of 990 instead of 899. How do you fix
it?" Answer: record a void of the wrong entry with a reason and record the correct one; both
are visible in the history, the total adds up, and the original entry is untouched.

---

## 3. Idempotency keys: the same order is never created twice

**Why.** A customer clicks "place order", the network hangs, they click again. Without
protection you get two identical orders and two charges. The same goes for payments: a
double click in the admin must not double the money.

**How it works.** The checkout screen sends a unique key (16-80 characters) together with
the order. The server stores the key with the order: the same key with the same content
returns the same order (no new one is created); the same key with different content is
refused with an `idempotency_conflict` error, because that already looks like a client bug.
Money ledger entries are protected by the same mechanism.

**Where in the code.** `api/src/Checkout/CheckoutService.php`, functions `place()` and
`sameRequest()`; the `Idempotency-Key` header is accepted by
`api/src/Controller/CheckoutController.php`; for money entries the key is stored by
`api/src/Entity/Payment.php`.

**What backs it up.** `api/tests/Checkout/CheckoutApiTest.php`, test
`testSameIdempotencyKeyTwiceGivesOneOrderAndAnotherBodyConflicts`;
`api/tests/Order/PaymentLedgerTest.php`, test
`testSameKeyReturnsTheFirstEntryAndAnotherBodyConflicts`.

**Interview question.** "Why is a key with a different order body a 409 and not a new
order?" Answer: a key is tied to a single intent of the buyer; a changed body under the same
key means the client is out of sync, and it is safer to refuse than to quietly create a
second thing.

---

## 4. Locks: two admins can't wreck one order or one evening

**Why.** An admin opens an order on two screens (or a repeated click fires): both copies see
"unpaid" and both record a payment, so the money is doubled. For installation bookings it is
worse: two requests check "is the evening free" within the same minute, both decide "free"
and both write, and the evening is oversold.

**How it works.** Every change to an order goes through one wrapper flow: a transaction in
which the order row is locked (`SELECT … FOR UPDATE`) until the change is done, so the second
copy simply waits and then works with the fresh data. For installation evenings there is a
Postgres advisory lock on the whole Prague day of the evening, waiting no more than
2 seconds. If it can't get the lock, it gives an honest answer, "someone else is booking this
evening right now, please try again" (409 `evening_busy`), and writes nothing.

**Where in the code.** `api/src/Order/OrderTransaction.php`, function `run()` is the wrapper
for order changes; `api/src/Order/OrderLoader.php`, function `load()` locks the row;
`api/src/Installation/InstallationService.php`, functions `withEveningLock()` and
`eveningLockKey()` handle the evening lock.

**What backs it up.** `api/tests/Admin/InstallationBookingTest.php`, test
`testABusyEveningRefusesTheSecondBookingUntilTheWriterIsDone`: a second connection holds the
evening lock, the booking gets a 409 `evening_busy` and nothing lands in the database; once
the lock is released, the same booking goes through.

**Interview question.** "Why is an advisory lock better than a row lock here?" Answer: it
exists independently of rows. You can "claim" an evening for the duration of the check, even
before a record exists, and it shows up in Postgres monitoring as a single key.

---

## 5. Supplier offer and own stock: one availability mechanism

**Why.** The shop promises products it doesn't physically have: it buys from a European seller
after the order. So "availability" is not a shelf count, it is a decision: is our own stock
enough, and if not, is there a live supplier offer with a price and lead time that can be
trusted.

**How it works.** Every product variant has its own stock (what actually sits with us) and
supplier offers: a link, a price, a declared quantity, a check date and a trust status (a
snapshot of someone else's claim, or matched to a specific variant). Availability and lead
time for the storefront are computed by a calculator: own stock means "in stock"; a verified
offer means "to order, N-M days"; a stale or unverified source means "ask us", and ordering
is not possible. Stock and offers are kept separate on purpose: demo supplier data is marked
and never mixed with real data.

**Where in the code.** `api/src/Pricing/AvailabilityService.php` computes availability for
the storefront; `api/src/Pricing/SourcingCalculator.php` and `api/src/Pricing/Sourcing.php`
make the "stock or supplier" decision; `api/src/Entity/SupplierOffer.php` is the offer with
its check date and status.

**What backs it up.** `api/tests/Pricing/SourcingCalculatorTest.php` covers the calculator
rules; `api/tests/Checkout/CheckoutSourcingTest.php`, test
`testUnavailableVariantIsRefusedAndNothingIsReserved`: without a live source the order
doesn't go through.

**Interview question.** "Why not just show 'in stock' like everyone else?" Answer: "in stock"
is a promise of immediate shipping, and most of the time we can't make it. An honest "to
order, 3-5 days" backed by a verified source cuts returns and disputes better than a nice
green button.

---

## 6. All deadlines follow the Prague wall clock

**Why.** The shop lives under Czech rules: a 14-day return window, 30 days to settle a
warranty claim, installation bookings in the evenings from 17:00 to 21:00. If deadlines are
counted by server time or UTC, then on the night the clocks change (in Europe that happens in
October) the "day" boundaries drift: a deadline can land a day early or an evening can be
split in two.

**How it works.** Calendar days are read as Prague days straight away: the date the customer
got in touch, the day of handover, the installation evening windows. Deadlines: a return
means refunding within 14 days of the contact day (not of the day the claim was accepted!),
a warranty claim means a decision within 30 days of that same day. For older claims that were
accepted before this rule existed, a migration recalculated the deadlines from the contact
day. Installation windows are checked against Prague wall-clock time, including the night the
clocks change.

**Where in the code.** `api/src/Order/ClaimService.php`, functions `open()` and `accept()`
handle claims and deadlines; `api/src/Entity/ReturnClaim.php` holds the norms (14/30 days,
constants `RETURN_REFUND_DAYS` and `WARRANTY_SETTLE_DAYS`); `api/src/Order/OrderQueues.php`
holds the time zone `TIMEZONE`; `api/src/Installation/InstallationService.php` handles the
installation evenings; `api/migrations/Version20260819213717.php` recalculates the old
deadlines.

**What backs it up.** `api/tests/Admin/ReturnClaimsTest.php`, tests
`testWindowsCountFromHandover` and `testAcceptingAgainKeepsTheDeadline`;
`api/tests/Migration/ClaimDueAtMigrationTest.php` covers the recalculation;
`api/tests/Admin/InstallationBookingTest.php`, test
`testTheDstChangeoverNightIsStillJudgedByPragueWallTime` covers the clock-change night.

**Interview question.** "A customer wrote at 00:30 Prague time on the day the clocks change.
Which day is that for the 14-day deadline?" Answer: the Prague calendar day. A server on UTC
would still see the previous day, and the deadline would shift by a day, which is why the
calendar is read as Europe/Prague from the start.

---

## 7. Product import: preview first, then apply

**Why.** A supplier feed is thousands of rows of someone else's data: wrong prices,
duplicates, mismatches with our catalog. Applying it straight to the database is a way to
wreck the storefront in a minute.

**How it works.** An uploaded file is first parsed and matched against the catalog (by EAN,
then by brand + part number), but nothing is written. The report shows how many new products,
updates, conflicts and errors there are, and estimates the price with a markup. The report is
saved as a "run" with a fingerprint of the file; applying requires the same file (checked by
sha256) and happens in a single transaction: all or nothing. Every field remembers which run
it came from.

**Where in the code.** `api/src/Import/ImportService.php`, functions `preview()` and
`apply()`; matching is in `api/src/Import/ImportPlanner.php`; field origin is recorded by
`api/src/Import/OriginRecorder.php`.

**What backs it up.** `api/tests/Import/ImportApiTest.php`, test
`testPreviewReportsCountsAndCostWithoutWriting`: a preview writes only its own run and leaves
the data alone; `api/tests/Import/ImportDedupTest.php` covers duplicates and conflicts.

**Interview question.** "The file changed between the preview and the 'apply' button. What
happens?" Answer: apply refuses (`file_changed`, checked by the file fingerprint) and asks
for a new preview, so exactly what the person saw is what gets applied.

---

## In short

| Decision | One sentence |
|---|---|
| Terms snapshot | An order remembers the promise as it was at purchase time |
| Money ledger | Fix with an entry and a reason, never delete |
| Idempotency | A repeated click doesn't create a second order |
| Locks | Two admins can't write into the same order or the same evening |
| Stock vs supplier | Availability = own stock or a verified offer |
| Prague calendar | All deadlines follow Prague wall-clock time |
| Import | Look at the report first, then apply in one step |
