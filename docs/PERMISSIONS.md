# Permission catalog

The source of truth for **Permissions Phase 1** (build step 4). It was taken from the role checks
in the code on 2026-10-03, so the grants below reproduce today's behavior exactly. Once
`App\Auth\Permissions` exists, the code becomes authoritative and a test keeps this file in step
with it.

## Permission design rules

Approved 2026-10-03. They explain the catalog; follow them when you add to it.

- **A permission answers "may I do this?" Scope answers "where may I do it?"** The two are
  checked separately: `authorize()` never replaces `scopeToProperty()` / `effectivePropertyId()`.
- **A permission never implies property access.** Holding `finance.invoice.settle` lets you settle
  invoices at a property you already belong to, nowhere else. "No property" never means "all
  properties" outside explicit platform permissions.
- **Deny by default.** Every API action calls `authorize()`. The only exceptions are
  `POST /auth/login`, `GET /auth/me` and `POST /auth/logout`. A user whose role isn't in the map
  holds nothing.
- **View and change are separate permissions** (`view` vs `manage`/`settle`/…).
- **Names are `module.resource.action`** and follow the process's **future owning module** (the
  ownership table in CLAUDE.md), not today's screen. Once stored (Phase 2), a name never changes,
  even if screens move.
- **Start coarse.** `manage` covers create/update/delete. Split only when a real role needs the
  difference, never in advance.
- **Explicit lists, no wildcards.** Definitions live in code (`App\Auth\Permissions`); from Phase 2
  the grants live in data.
- **Elevated permissions need accountability support.** They will require a reason once the event
  foundation exists (steps 5–8). In Phase 1 this is only a flag.
- **New modules define their permissions before implementation**, as part of the design review:
  catalog rows, endpoint map rows and access-matrix probes land with the first endpoint.
- **Gates are on actions, not routes.** The API scope has `fallbacks()`, so an action can also be
  reached at `/api/<controller>/<action>`. A check inside the action covers every route to it.
- **Screens combine permission, scope and context, never permission alone.** A Hub tile or route
  opens for a person in its scope (platform or property) who holds its permission
  (`frontend/src/nav.js`, `canOpen()`). The Platform Owner holds some hotel permissions today and
  still sees only platform screens.
- **Business rules stay business rules.** State locks, separation of duties and the staff
  hierarchy are not permissions (see "Rules that stay outside permissions").

Holders: **PO** = Platform Owner (`owner`), **M** = Manager (`admin`), **FD** = Front Desk Staff
(`receptionist`). † = held by the Platform Owner today only because the endpoint has no check, or
checks `owner, admin` for hotel configuration. This is recorded in `BACKLOG.md` ("Platform Owner
access to hotel data") and goes away in Phase 2, when the Platform Owner becomes a platform flag.

Future roles (Phase 3, examples): Property Owner, Accountant, POS Cashier, Housekeeping,
Maintenance, Storekeeper. A multi-property Manager isn't a role: it's a Manager membership at
several properties.

## Catalog

| Permission | Current holders | Future holders | Elevated | Owning module |
|---|---|---|---|---|
| `platform.dashboard.view` | PO | Platform Owner (platform flag) | | Platform |
| `platform.property.manage` | PO | Platform Owner (platform flag) | | Platform |
| `operations.today.view` | M, FD | Manager, Front Desk, Property Owner, Housekeeping, Maintenance | | Operations |
| `operations.staff.view` | M | Manager, Property Owner | | Operations |
| `finance.collections.view` | PO†, M, FD | Manager, Front Desk, Accountant, Property Owner, POS Cashier | | Finance |
| `finance.collections.view_range` | PO†, M | Manager, Accountant, Property Owner | | Finance |
| `finance.analytics.view` | M | Manager, Accountant, Property Owner | | Finance |
| `finance.invoice.view` | PO†, M, FD | Manager, Front Desk, Accountant, Property Owner | | Finance |
| `finance.invoice.settle` | PO†, M, FD | Manager, Front Desk, Accountant | | Finance |
| `finance.invoice.reverse` | M | Manager, Accountant | yes | Finance |
| `finance.receipt_series.manage` | PO†, M | Manager, Accountant | | Finance |
| `front_desk.reservation.view` | PO†, M, FD | Manager, Front Desk, Accountant, Property Owner, POS Cashier | | Front Desk |
| `front_desk.reservation.manage` | PO†, M, FD | Manager, Front Desk | | Front Desk |
| `front_desk.reservation.backdate` | M | Manager | yes | Front Desk |
| `front_desk.reservation.correct` | M | Manager | yes | Front Desk |
| `front_desk.reservation.delete` | M | Manager | yes | Front Desk |
| `guests.guest.view` | PO†, M, FD | Manager, Front Desk, POS Cashier, Property Owner | | Guests |
| `guests.guest.manage` | PO†, M, FD | Manager, Front Desk, POS Cashier | | Guests |
| `pos.sale.view` | PO†, M, FD | Manager, Front Desk, POS Cashier, Accountant, Property Owner | | POS |
| `pos.sale.manage` | PO†, M, FD | Manager, Front Desk, POS Cashier | | POS |
| `pos.sale.cancel_paid` | PO†, M | Manager | yes | POS |
| `pos.menu.manage` | PO†, M | Manager | | POS |
| `rooms.room.view` | PO†, M, FD | Manager, Front Desk, Housekeeping, Maintenance, Property Owner | | Rooms |
| `rooms.room.update_status` | PO†, M, FD | Manager, Front Desk, Housekeeping, Maintenance | | Rooms |
| `inventory.item.view` | PO†, M, FD | Manager, Front Desk, Storekeeper, Property Owner | | Inventory |
| `inventory.item.manage` | PO†, M | Manager, Storekeeper | | Inventory |
| `inventory.category.manage` | PO†, M | Manager, Storekeeper | | Inventory |
| `inventory.stock.adjust` | PO†, M | Manager, Storekeeper | | Inventory |
| `settings.property.view` | PO, M, FD | every property role | | Settings |
| `settings.configuration.view` | PO†, M, FD | Manager, Front Desk, Property Owner | | Settings |
| `settings.room.manage` | PO†, M | Manager | | Settings |
| `settings.room_rate.manage` | PO†, M | Manager | | Settings |
| `settings.promo_rate.manage` | PO†, M | Manager | | Settings |
| `settings.extra_charge.manage` | PO†, M | Manager | | Settings |
| `staff.account.view` | PO, M | Manager, Property Owner | | Staff |
| `staff.account.manage` | PO, M | Manager | | Staff |

36 permissions: 2 platform, 34 property.

## Endpoint map

| Endpoint (action) | Permission | Notes |
|---|---|---|
| `GET /platform/dashboard`, `GET /reports/owner-dashboard` | `platform.dashboard.view` | |
| `POST /properties`, `PATCH/PUT /properties/{id}` | `platform.property.manage` | |
| `GET /properties` | `settings.property.view` | Staff see their own property only; the Platform Owner sees all, with Managers (scope, not permission) |
| `GET /operations/today`, `GET /reports/operations` | `operations.today.view` | Staff and activity sections only with `operations.staff.view` |
| `GET /operations/activity`, `GET /reports/activity` | `operations.staff.view` | |
| `GET /finance/collections`, `GET /reports/daily-collection` | `finance.collections.view` | A month or date range also needs `finance.collections.view_range` |
| `GET /finance/summary`, `GET /reports/admin-dashboard`, `GET /finance/seasonality`, `GET /reports/monthly-summary` | `finance.analytics.view` | |
| `GET /invoices`, `GET /invoices/{id}`, `GET /receipt-series` | `finance.invoice.view` | Booklets are listed to settle against |
| `POST /invoices/{id}/settle` | `finance.invoice.settle` | Locked, recorded once; a second settle is 400 |
| `POST /invoices/{id}/lines/{lineId}/reverse` | `finance.invoice.reverse` | Needs `reason`; open invoices only (build step 6) |
| `POST/PATCH/PUT/DELETE /receipt-series…` | `finance.receipt_series.manage` | |
| `GET /reservations`, `GET /reservations/stats` | `front_desk.reservation.view` | |
| `POST /reservations`, `PATCH/PUT /reservations/{id}` (a booking), `POST /reservations/{id}/{transition}`, `POST /reservations/{id}/post-room-charge`, `POST /reservations/{id}/payment` (legacy) | `front_desk.reservation.manage` | |
| Booking or moving a check-in before today | `front_desk.reservation.backdate` | Rule kept: a walk-in without it is forced to today, not refused |
| Editing a checked-in/checked-out stay | `front_desk.reservation.correct` | Refused with **400**, not 403, today; Phase 1 keeps the status code |
| `DELETE /reservations/{id}` | `front_desk.reservation.delete` | |
| `GET /guests`, `/guests/stats`, `/guests/match`, `/guests/{id}` | `guests.guest.view` | |
| `POST /guests`, `PATCH/PUT /guests/{id}` | `guests.guest.manage` | |
| `GET /food-orders`, `GET /food-orders/{id}`, `GET /food-menu-items` | `pos.sale.view` | |
| `POST /food-orders`, `/food-orders/{id}/serve`, `/food-orders/{id}/cancel` | `pos.sale.manage` | Cancelling a served and paid order also needs `pos.sale.cancel_paid` |
| `POST/PATCH/PUT/DELETE /food-menu-items…` | `pos.menu.manage` | |
| `GET /rooms` | `rooms.room.view` | |
| `PATCH/PUT /rooms/{id}` (status only) | `rooms.room.update_status` | Changing number or type needs `settings.room.manage` |
| `POST /rooms`, `DELETE /rooms/{id}` | `settings.room.manage` | |
| `GET /inventory-items`, `/inventory-items/{id}`, `/inventory-categories`, `/stock-movements` | `inventory.item.view` | |
| `POST/PATCH/PUT/DELETE /inventory-items…` | `inventory.item.manage` | |
| `POST /inventory-categories`, `DELETE /inventory-categories/{id}` | `inventory.category.manage` | |
| `POST /stock-movements` | `inventory.stock.adjust` | POS and check-in stock changes go through `StockMovementsTable::record()` under the POS permission, not this one |
| `GET /room-rates`, `/promo-rates`, `/booking-sources`, `/extra-charges` | `settings.configuration.view` | Read by the booking form |
| `POST/PATCH/PUT /room-rates…` | `settings.room_rate.manage` | |
| `POST/PATCH/PUT/DELETE /promo-rates…` | `settings.promo_rate.manage` | |
| `POST/PATCH/PUT/DELETE /extra-charges…` | `settings.extra_charge.manage` | |
| `GET /users` | `staff.account.view` | |
| `POST /users`, `PATCH/PUT /users/{id}`, `POST /users/{id}/reset-password` | `staff.account.manage` | Rules kept: who may manage whom (below) |

## Rules that stay outside permissions

- **Who may manage whom.** A Manager creates and resets only Front Desk Staff in their own
  property; the Platform Owner creates Managers and Front Desk Staff; nobody deactivates
  themselves. Kept as written in Phase 1. Phase 3 replaces it with "you may assign only a role
  whose permissions you already hold".
- **Record state.** A settled reservation is locked; a booking with a downpayment can't be edited.
  Nobody gets past these.
- **Separation of duties** (future: expenses, purchase orders, timesheets) compares the actor
  with the record, so it's never a permission.
- **Queries about roles** (staff counts in Operations, Managers on the Platform dashboard and
  Subscribers) are reports, and move to memberships in Phase 2.

## Split candidates (only when a real role needs them)

- `pos.sale.charge_to_room`: a POS Cashier who may sell but not post to a guest's invoice.
- `front_desk.reservation.manage` is **the most likely to split** (noted 2026-10-03). It covers
  booking, editing, check-in/out, cancelling and posting the room charge. A Reservation Agent
  (books only), a night auditor (check-out only) or an Accountant (posts charges only) would each
  need part of it.
- `settings.configuration.view` → per configuration table: if a role needs rooms but not rates.
