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

POS sales. Subject `food_order_id`; activity subject `food_order`. Typed columns: `amount`, `method` and
`idempotency_key` (refunds).

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `placed` | | | A sale is placed (`FoodOrdersTable::place()`) |
| `served` | | | An open sale is served |
| `cancelled` | | | A sale is cancelled, other than below |
| `cancelled_after_payment` | yes | yes | A served and paid sale is cancelled (`pos.sale.cancel_paid`) |
| `refunded` | yes | | Money is returned for a paid sale ("Was money returned?" yes on cancelling it, or `POST /food-orders/{id}/refund` later): `amount` negative, `method`; once per sale |

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
`amount`, `method` and `idempotency_key` (refunds), `total_after`, `invoice_line_id`, `invoice_number`, `or_number`. Snapshot: guest id and
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
| `refund_recorded` | | | Before step 7c: a downpayment refund written as a line on the settled invoice. No longer written; its rows and lines stay as history |
| `refunded` | yes | | A Manager returns money against a settled invoice (`finance.invoice.refund`, `POST /invoices/{id}/refund`): `amount` negative, `method`; the invoice is untouched |
| `refunded_on_cancel` | | | The downpayment refund when an advance booking is cancelled (policy in `changes`): `amount` negative, `method`; once per invoice |

Rules (decided 2026-10-03):
- **Lines are never deleted.** A reversed line stays, answered by a negative line with
  `reverses_line_id`; the total is the sum of all lines (floored at zero after a reversal, as
  removing lines always was). "Is this charge posted?" checks use `InvoiceLinesTable::find('active')`,
  which ignores both; the reservation delete guard still counts them, as history.
- **Settled invoices are immutable.** Money given back is a refund event (`refunded`,
  `refunded_on_cancel`; step 7c), never a line, so the invoice still matches its printed SI/OR.
  The step 6 exception (`refund_recorded`, a refund line on the downpayment invoice) is no longer
  written; Collections counts those old lines as cash out on the day they were written.
- **A refund is recorded exactly once** (step 7c): the screen sends an `idempotency_key` made
  when the refund dialog opens (unique index; a repeat is 409 and records nothing), a sale has at
  most one `refunded`, an invoice at most one `refunded_on_cancel`, and an invoice is never
  refunded beyond what it collected less earlier refunds. `occurred_at` is the refund's date: no
  backdating.
- **Settlement is idempotent:** a second settle is refused, consumes no receipt number, records no
  event and changes no figure (`SettlementApiTest`).

### reservation_events (`ReservationEvents`)

Front Desk: the reservation lifecycle (build step 8, approved 2026-10-04 as R1–R8). Subject
`reservation_id`; activity subject `reservation`. Typed columns: `room_id`, `status_after`.
Snapshot: guest id and display name, room number, dates, status, guest count. `changes` holds
`{field: {before, after}}` for every tracked field that changed (including the Senior/PWD
beneficiaries with names and ID numbers, and the extra charges); a creation holds `{after: …}` and a
deletion `{before: …}`. Written only by `ReservationsController`, one event per action, with the
reservation row locked (`ReservationsTable::lockReservation()`), re-checked, changed and recorded in
one transaction.

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `booked` | | | A future booking is created (it holds the room) |
| `walked_in` | | | A walk-in is created, checked in at once (it occupies the room) |
| `backdated` | yes | | A Manager enters a past stay, or moves a booking's check-in before today; `occurred_at` is when it was entered, the stay's dates are in `changes` |
| `edited` | | | A booking is changed before check-in |
| `corrected` | yes | | A Manager corrects a checked-in or checked-out stay |
| `discount_changed` | | | A booking's discounts change after it was made (setting or changing a referral amount needs a reason, asked by the controller) |
| `checked_in` | | | Check-in (`early_check_in` in `changes` when its fee was posted) |
| `checked_out` | | | Check-out (`room_charge_posted` when the check-out posted it) |
| `cancelled` | | | Cancelled with no money taken |
| `cancelled_after_payment` | yes | | Cancelled after a downpayment was collected or charges were posted |
| `deleted` | yes | | A Manager deletes a reservation entered by mistake: soft, the whole reservation in `changes` |

Rules (decided 2026-10-04):
- **One event per action**, refused with the action: the second of two identical check-ins,
  check-outs, cancellations, corrections, backdated entries or deletions is refused, records no
  event and changes nothing (`ReservationEventsApiTest`). An edit that changes nothing is refused.
- **A referral discount needs a reason** (R3) when it is set or changed, at booking or on an edit;
  statutory and channel discounts don't (law and configuration decide them).
- **Soft delete** (R4): `reservations.deleted_at`; `ReservationsTable` hides deleted rows from every
  query unless asked (`find('all', withDeleted: true)`). The row, its discounts and extras stay; no
  restore. Deleted means "historically happened", not "temporarily hidden".
- **Money stays in `invoice_events`**: a check-out's room charge, a cancellation's reversals and
  refund share the action's correlation id with its reservation event; nothing is copied.
- **`reservations.receptionist_id` is legacy** (R6): still stamped for one release, never the actor.
- **Timeline and feed** (part 2): `GET /reservations/{id}/history` merges these events with the
  invoice events of the reservation's money; Operations → Activity shows every type but `edited`.
  Staff actions today count distinct requests per actor across all ledgers (`activity_index`).
- **History before step 8** (`BackfillReservationEvents`, `bin/cake activity_backfill`): a creation
  event at `created` (`walked_in` for a walk-in, `booked` otherwise), `checked_in` at
  `checked_in_at` (not for walk-ins and past stays, created checked in), `checked_out` at
  `checked_out_at`, `cancelled` at `cancelled_at`. Actor, role, reason and room are NULL; the
  snapshot keeps `receptionist_id` as `last_touched_by_before_step_8`, the room and status as
  `room_at_import` / `status_at_import`; a stay whose moments predate its `created` is flagged
  `backdated_entry`. Edits, corrections, discount changes and deletions before step 8 were never
  recorded and aren't imported; a reservation with no `created` is left out and counted.

### config_changes (`ConfigChanges`)

Configuration (build step 9, approved 2026-10-04 as C1–C9 plus impact classes). One shared ledger
for every configuration table: room rates, promo rates, booking sources, extra charges, rooms,
receipt booklets, menu items (with their recipe and options), inventory categories, property
records. Subject `entity_id` with `entity_type` (`room_rate`, `promo_rate`, `booking_source`,
`extra_charge`, `room`, `receipt_series`, `menu_item`, `inventory_category`, `property`); the
activity-index row's subject type is the entity type. Typed columns: `entity_type`, `entity_id`,
`impact`. `changes`: `{after: …}` on creation, `{before: …}` on deletion, `{field: {before,
after}}` on an update (a menu item's `recipe`, `options` and `option_prices` included). Snapshot:
`{label}` (the row's name, room number, source…). Written only by `ConfigAuditBehavior`, inside the
save's own transaction.

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `created` | | | A configuration row is added |
| `updated` | | | Audited fields change; **a reason is required when the impact is `price`** (enforced by `ConfigAuditBehavior`) |
| `deleted` | yes | | A row is soft-deleted (`deleted_at`): every value kept |
| `baseline_recorded` | | | Import: the row as it stood when the audit began (no actor) |

Impact classes (highest of the changed fields; a creation or deletion takes the entity's own):
`price` (feeds a price: room rate, promo multiplier and its source/room, extra-charge amount, menu
and option prices, subscription fee) > `booking` (what can be booked: rooms, booking sources, a
charge switched on or off) > `operational` (how the property runs: room number and type, rate
descriptions, menu names, recipes and availability, subscription status) > `administrative`
(names and records: receipt booklets, inventory categories, labels).

Rules (decided 2026-10-04):
- **No actor, no save:** a save that changes an audited field must carry `eventContext` (jobs and
  auto-seeding pass a system context: the built-in early check-in charge is recorded with
  `source: system`). Ignored fields are operational and need none: a room's `status` (the future
  Rooms module, C5) and a receipt booklet's `next_number` (usage).
- **Soft delete only** (C3): a hard delete is refused; deleted rows are hidden from top-level
  queries unless `withDeleted`, but still load through associations (a reservation keeps its
  deleted room's number). No restore.
- **No empty change:** an edit that changes nothing is refused ("Nothing to change") and records
  nothing.
- **History before step 9** (part 2, `ConfigBaseline`): one `baseline_recorded` per row with no
  change, its values at import, dated at import, no actor or reason, correlation
  `import-config-<entity>-<id>`; idempotent, checked per property. Nothing earlier is invented.
- **Read by** Settings → Change log (`GET /config-changes`, Manager; property records:
  `GET /platform/property-changes`, Platform Owner) and, for price changes to existing rows and deletions only,
  Operations → Activity (C4). A booking's price view (`GET /reservations/{id}/price`) lists the
  changes to its rate inputs since it was made.
- **Side effects are their own changes, in the same request:** a booking source created by a
  promo-rate save; each sub-category detached when its parent category is deleted.

### access_events (`AccessEvents`)

Access (build step 10, approved 2026-10-05; part 1 = release 10a). Every change to who can get
in, and every sign-in. Subject `subject_user_id`: the person whose access it is; the actor is
whoever acted (the Manager who reset a password, the person signing in). Typed columns: `scope`
(`property` | `platform`), `user_agent` and `client_address` (sign-ins only, as the edge
reported them: kept to recognise a sign-in, never used to allow or refuse, A4). **Platform
events** (the Platform Owner's own account and sign-ins) have no `property_id` (`scope =
platform`, A2) and are never indexed. **Only account administration is indexed** for the feed
(`FEED_TYPES`: created, deactivated, reactivated, password reset); sign-ins never are (A9).
Snapshot: `{name, role}` of the person (never their email). Written by `AuthController` and
`UsersController` (and `create_user`, as `system`), each under a `FOR UPDATE` lock on the user
row, in the change's own transaction.

| Event type | Requires reason | Reason grace | Recorded when |
|---|---|---|---|
| `account_created` | | | An account is made (`changes.after`: name, role, property, active; never the email) |
| `account_renamed` | | | Its name changes |
| `account_deactivated` | yes | | It's switched off; its session ends in the same request (`session_ended`) |
| `account_reactivated` | yes | | It's switched back on; no old session comes back (F1) |
| `password_reset` | yes | | Someone sets another person's password; their session ends |
| `password_changed` | | | A person changes their own, giving their current one (A10) |
| `signed_in` | | | A successful sign-in (`changes.ended_other_session` when it replaced a session) |
| `sign_in_failed` | | | A wrong password for an existing account; **no actor** (nobody was proven) |
| `sign_in_locked` | | | The failure that paused the address (login throttle) |
| `sign_in_refused` | | | The right password for an inactive account (answered as any failure) |
| `signed_out` | | | The person signs out |
| `session_ended` | | | A deactivation or a password set ended the person's session (`changes.because`) |
| `membership_granted` | | | A person gets a role at a property: a new account (`UsersController`, `create_user`) (`membership_id`, `role_id`; `changes.after`: property, role) |
| `membership_imported` | | | Import (step 10b): a membership made from the account's `users.role` / `users.property_id`; **no actor** |
| `platform_access_granted` | | | The platform flag is set: `create_user` for an owner, or the import for today's Platform Owner (platform scope) |

Rules (decided 2026-10-05):
- **A failed sign-in has no actor** and `source: web` (`EventContext::unauthenticated()`): the
  one exception to "a web event has an actor". A failure for an address with no account isn't
  stored (no subject, and the typed address could be a password, A3); the server log says one
  happened, with the request id.
- **Failed, locked and refused attempts are recorded best effort**: if the write fails, the
  person still gets their answer and the log says so. A successful sign-in and its event are one
  transaction.
- **Nothing to change is refused**: deactivating an inactive account (a repeated request)
  answers 400 and records nothing.
- **History before step 10** (`AccessBackfill`, migration `BackfillAccountHistory`, A13): one
  `account_created` per account, dated at its own `created`, no actor or reason, correlation
  `import-users-<id>`, its active state at import in the snapshot. Deactivations, resets and
  sign-ins before step 10 were never recorded and aren't invented. An account naming a property
  that doesn't exist, or with no creation time, is reported and left out.
- **Memberships (step 10b, Permissions Phase 2).** Access is read from the person's active
  membership (`property_memberships`: user × property × role, never deleted, ended with
  `ended_at`) and its role's `role_permissions`, or from the platform flag (`users.is_platform`).
  Typed columns `membership_id` and `role_id`. The role presets and their grants are seeded from
  `Permissions::ROLE_GRANTS` by migration with **no event**: they are code a migration ships, not
  something a person did (Phase 3 makes roles editable, and audited). A sign-in with the right
  password but no active membership is `sign_in_refused` with `changes.because = no_membership`.
- **Membership import** (`MembershipImport`, migration `ImportMemberships`): one
  `membership_imported` per Manager or Front Desk account with an existing property and no
  membership, dated at the import, no actor or reason, correlation `import-users-<id>`; the
  membership's `started_at` is the account's own `created`; inactive accounts keep their
  membership (nobody ended it) and say `active_at_import` in the snapshot. One
  `platform_access_granted` per owner account. Idempotent; re-run with `bin/cake activity_backfill`.
- **Read by** your own account (`GET /auth/sign-ins`, everyone), a Manager for their staff
  (`GET /users/{id}/access-history`, `staff.access_history.view`), and Operations → Activity
  (account administration only).

## Planned

Release 10b, parts 2 and 3, adds role changes, membership ends and support access to
`access_events` (10b proposal, section 7); no new ledger.
