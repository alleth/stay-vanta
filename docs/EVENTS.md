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

## Reasons

A type in `REQUIRES_REASON` can't be recorded without one; the endpoint also checks first with
`authorizeElevated()`, so the user gets a clear 400 before anything changes. `REASON_GRACE`
lists required types still accepted without a reason during a compatibility window (a released
frontend can't send one yet). Grace entries are removed in the release that closes the window.

## Privacy

Snapshots keep the minimum: ids, amounts, states, room numbers and a guest's id and display name.
Never contact details, government ID numbers or other sensitive personal data. Redaction (for an
erasure request) will be one controlled routine that blanks personal fields in snapshots and
records its own `redacted` event; it's the only sanctioned change to a ledger row.

## Ledgers

### food_order_events (`FoodOrderEvents`)

POS sales. Subject `food_order_id`; activity subject `food_order`. Typed column: `amount`.

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `placed` | | | A sale is placed (`FoodOrdersTable::place()`) |
| `served` | | | An open sale is served |
| `cancelled` | | | A sale is cancelled, other than below |
| `cancelled_after_payment` | yes | yes | A served and paid sale is cancelled (`pos.sale.cancel_paid`) |

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
stock are one transaction. Rows from before step 5 have no `correlation_id`/`actor_role`/`source`/
`occurred_at` (nullable until the cleanup release makes them `NOT NULL`).

## Planned

| Ledger | Build step |
|---|---|
| `invoice_events` | 6 |
| `reservation_events` | 8 |
| `config_changes` (ConfigAuditBehavior) | 9 |
| `access_events` | 10 |
