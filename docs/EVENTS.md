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

## Planned

| Ledger | Build step |
|---|---|
| `stock_movements` (existing table, gains the shared columns) | 5, part 2 |
| `invoice_events` | 6 |
| `reservation_events` | 8 |
| `config_changes` (ConfigAuditBehavior) | 9 |
| `access_events` | 10 |
