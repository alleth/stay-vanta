# Event catalog

The source of truth for what each ledger records. The standard behind it (who / what / when /
why, append-only, same transaction) is CLAUDE.md, "Accountability standard"; the design was
approved as build step 5 on 2026-10-03. `EventsCatalogTest` keeps this file and the code
identical: a type, reason rule or ledger added in one must be added in the other.

## How an event is recorded

```php
$connection->transactional(function () use (...) {
    // 1. lock the subject (FOR UPDATE), 2. check, 3. change it, then:
    $this->fetchTable('FoodOrderEvents')->record($this->eventContext(), FoodOrderEventsTable::SERVED, $order, [
        'changes' => ['status' => ['open', 'served']],
        'columns' => ['amount' => $order->total],
    ]);
});
```

- **Lock, then check, then change, then record** (an official rule): re-read the subject with
  `FOR UPDATE` inside the transaction, check against that row, change it, record the event. It
  applies to every ledgered action, named first for invoice settlement, reservation check-in/out
  and correction, and expense and purchase order approvals. Test it: the second of two identical
  requests is refused and records nothing (`LedgerWritesApiTest` does this for serve and cancel).
- **One way in:** `EventLedgerBehavior::record()`. It refuses to run outside a transaction, so if
  the event can't be written the change rolls back with it.
- **Context:** `AppController::eventContext()` (built once per request) or
  `EventContext::system()` for jobs. It carries the actor, their role at that moment, the
  property, the correlation id, the source and the reason.
- **Append-only:** ledger tables refuse updates and deletes, including `updateAll()`/`deleteAll()`.
  A mistake is corrected by a new event with `corrects_event_id`.
- **Feed:** each event also writes one `activity_index` row in the same transaction.

## Shared columns (every ledger)

`property_id`, the subject id, `event_type`, `actor_id` (NULL only for `system`/`import`),
`actor_role`, `source` (`web` | `system` | `import`), `reason`, `changes` (JSON, before/after),
`snapshot` (JSON, the facts as they were), `correlation_id`, `occurred_at` (UTC), `corrects_event_id`,
`created`, plus typed columns for anything filtered or summed.

## Correlation ids (mandatory)

`CorrelationIdMiddleware` gives every request one id, returned as the `X-Request-Id` header on
every response (errors included) and never taken from the client. Every event of the request
carries it, so `ActivityIndexTable::forCorrelation($propertyId, $id)` reconstructs a whole
business action across ledgers: a checkout, a settlement, a correction, a cancellation after
payment. Jobs get `system-<job>-<uuid>`; rows backfilled from before step 5 get
`import-<table>-<id>`.

The same id prefixes every server log line written during the request (`[req:<id>]`,
`App\Log\RequestIdFormatter`), and deployed environments log to stderr, which Railway keeps. So
one id links a user's report (the id shown with an error), the server log and the event records.

## The activity feed (decided 2026-10-03)

**Long term, Operations → Activity is an event feed:** one line per business event (placed,
served, cancelled, settled, checked in, corrected…), each with who did it, rather than one line
per record showing its current state. In step 5 the feed stays visually identical to today
(`ActivityFeedApiTest` pins it: an order shows its current status). It moves to event lines when
the invoice and reservation ledgers arrive (steps 6 and 8), with a test for each new line type.

**Step 6: the first event lines.** Invoice events appear as their own lines (`type: 'invoice'`):
`settled` (with SI/OR numbers), `settled_on_creation` (downpayment collected), `line_reversed`
(with the line and reason), `line_reversed_on_cancel` and `refund_recorded`. `opened` and
`line_added` stay out: every sale and stay posts them and the sale lines already show. These lines
show the event as it happened, at `occurred_at` with its actor and amount; imported history has
`actor: null` and `recorded: false` and the screen says "Not recorded". Stock and sale lines are
unchanged (`FeedEquivalenceApiTest`). At the same instant: stock, then sales, then invoice events.
Grouping one action's events under its correlation id is for the step 8 feed redesign
(`docs/BACKLOG.md`).

## Reasons

A type in `REQUIRES_REASON` can't be recorded without one; the endpoint also checks first with
`authorizeElevated()`, so the user gets a clear 400 before anything changes. `REASON_GRACE`
lists required types still accepted without a reason during a compatibility window (a released
frontend can't send one yet). Grace entries are removed in the release that closes the window.

Every use of a grace entry logs a warning (`reason grace used: <table> <type> recorded without a
reason (subject <id>)`). A window closes only after the frontend that sends a reason has run in
production for at least one release **and** these lines have stopped appearing in Railway's logs.
The UI asks with the shared `ReasonModal` (`frontend/src/components/ReasonModal.jsx`): first for
cancelling a served, paid POS sale; next for invoice reversals and reservation delete, correct and
backdate.

## Backfills

Every backfill of history into a ledger or `activity_index` is **idempotent** (re-running adds
nothing), **auditable** (`source = 'import'`, `import-<table>-<id>` correlation ids, a per-property
count check logged) and **repeatable** (a batched command beside the migration). It never invents
what the data didn't record: no actor, no reason, no unobserved event.

The first one is `App\Event\ActivityBackfill` (step 5, part 3): a `placed` event for every sale
without one, and an `activity_index` row for every POS event and stock movement without one. It runs
once in the `BackfillActivityIndex` migration and again any time with `bin/cake activity_backfill`
(`--property N`, `--check-only`). It never changes a ledger row (old `stock_movements` keep their
nulls; only their index row says `import-`), and leaves out rows with no recorded time, counting
them as undated in the check.

## Privacy

Snapshots keep the minimum: ids, amounts, states, room numbers and a guest's id and display name.
Never contact details, government ID numbers or other sensitive personal data. Redaction (for an
erasure request) will be one controlled routine that blanks personal fields in snapshots and
records its own `redacted` event; it's the only sanctioned change to a ledger row.

## Ledgers

### food_order_events (`FoodOrderEvents`)

POS sales. Subject `food_order_id`; activity subject `food_order`. Typed columns: `amount`, `method` (refunds).

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `placed` | | | A sale is placed (`FoodOrdersTable::place()`) |
| `served` | | | An open sale is served |
| `cancelled` | | | A sale is cancelled, other than below |
| `cancelled_after_payment` | yes | yes | A served and paid sale is cancelled (`pos.sale.cancel_paid`) |
| `refunded` | yes | | Money is returned for a paid sale on its cancellation ("Was money returned?" yes): `amount` negative, `method`. Written from step 7c-2 |

Grace for `cancelled_after_payment` ends in the cleanup release after step 5.

Recorded by `FoodOrdersTable::place()`, `serve()` and `cancelOrder()`, each in one transaction
with the order row locked (`FOR UPDATE`) before its check, so a double click can't serve or
restock twice. A sale's stock movements share its correlation id.

### stock_movements (`StockMovements`)

Inventory. The row *is* the movement, so `StockMovementsTable::record()` builds it and
`EventLedgerBehavior::write()` stamps the shared columns: `receptionist_id` is the actor column,
`direction` implies the type (not stored again; `activity_index` has it), and the row's own
`reason` ("restock", "food_order", what a Manager typed) is kept. Subject `inventory_item_id`;
activity subject `inventory_item`. Snapshot: item name, unit, quantity after.

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `moved_in` | | | Stock comes in (restock, return, opening balance, a sale's restock on cancel) |
| `moved_out` | | | Stock goes out (consumed, issued, retired, sold) |

`record()` locks the item row (`FOR UPDATE`) and computes the new quantity from it, so two
movements of one item can't both start from the same quantity. Creating an item and its opening
stock are one transaction.

**Intentional exception for history (decided 2026-10-03):** rows from before step 5 have no
`correlation_id`, `actor_role`, `source` or `occurred_at`, and **keep them NULL for good**. Filling
them in would mean rewriting ledger rows or inventing facts, both against the accountability model.
NULL there means "predates step 5". Every new row must have them: `EventLedgerBehavior` stamps them
on each write, so the columns stay nullable in the schema while new writes can't leave them empty.

### invoice_events (`InvoiceEvents`)

Finance: invoices (build step 6). Subject `invoice_id`; activity subject `invoice`. Typed columns:
`amount`, `method` (refunds), `total_after`, `invoice_line_id`, `invoice_number`, `or_number`. Snapshot: guest id and
display name, reservation, status, total. Recorded only by `InvoicesTable`, each change locked
(`FOR UPDATE`), checked, made and recorded in one transaction.

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `opened` | | | A guest's invoice is opened (`openInvoiceFor()`, the guest row locked so there's one) |
| `line_added` | | | A charge, discount, credit or extra is posted (`addLine()`; refused on a settled invoice) |
| `line_reversed` | yes | | A Manager reverses a line by hand (`finance.invoice.reverse`, `POST /invoices/{id}/lines/{lineId}/reverse`, or Front Desk's `POST /reservations/{id}/reverse-room-charge`) |
| `line_reversed_on_cancel` | | | A line's source was cancelled (a sale, a reservation's charges) |
| `settled` | | | An invoice is settled, once; carries the SI/OR numbers |
| `settled_on_creation` | | | An invoice created settled (an advance booking's downpayment) |
| `refund_recorded` | | | The one permitted change to a settled invoice: a downpayment refund on cancellation. No longer written from step 7c-2 |
| `refunded` | yes | | A Manager returns money against a settled invoice (`finance.invoice.refund`): `amount` negative, `method`; the invoice is untouched. Written from step 7c-2 |
| `refunded_on_cancel` | | | The downpayment refund when an advance booking is cancelled (policy in `changes`): `amount` negative, `method`. Written from step 7c-2 |

Rules (decided 2026-10-03):
- **Lines are never deleted.** A reversed line stays, answered by a negative line with
  `reverses_line_id`; the total is the sum of all lines (floored at zero after a reversal, as
  removing lines always was). "Is this charge posted?" checks use `InvoiceLinesTable::find('active')`,
  which ignores both; the reservation delete guard still counts them, as history.
- **Settled invoices are immutable**, except `refund_recorded`: cancelling an advance booking adds
  its 90% refund to the downpayment's settled invoice, so the refund lowers Collected on the
  downpayment's day. Kept as is in step 6; refunds, credit notes and what Collected means are on
  step 7's agenda (`docs/BACKLOG.md`).
- **Settlement is idempotent:** a second settle is refused, consumes no receipt number, records no
  event and changes no figure (`SettlementApiTest`).

## Planned

| Ledger | Build step |
|---|---|
| `reservation_events` | 8 |
| `config_changes` (ConfigAuditBehavior) | 9 |
| `access_events` | 10 |
