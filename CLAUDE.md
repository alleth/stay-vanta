# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

StayVanta — "All-in-One Hotel & Resort Management Platform". A monorepo of two
independently-deployed apps that talk over a JSON API:

- `frontend/` — React 19 SPA (Vite), react-router-dom v7, axios. Styling is **pure Tailwind v4**
  (no Bootstrap): design tokens live in `src/index.css` `@theme` (so `text-muted`, `bg-subtle`,
  `border-line`, `bg-ink`, `text-accent`… are utilities), and the in-house kit
  `src/components/ui.jsx` provides Button/Badge/Card/Table/Modal/Form/Alert/Tabs/etc. with
  react-bootstrap-style APIs (`variant`, `size`, `show`/`onHide`). The one external UI library is
  `apexcharts`/`react-apexcharts` for Finance → Analytics' seasonality chart, loaded via `React.lazy()`
  (~200KB gzipped) so only an admin who opens that chart pays for it.
- `backend/` — **CakePHP 5** JSON REST API. **MySQL** (MariaDB via XAMPP locally; MySQL 9 on Railway).
  Full per-endpoint contract: **`backend/API.md`** — update it when you add or change an endpoint.

Frontend → Cloudflare Pages; backend → Railway. Different origins in production (CORS), same
origin in dev (Vite proxy).

## Product architecture (approved 2026-10-02)

These decisions govern every recommendation, design and code change. If a task conflicts with
one, say so instead of quietly deviating. Full rationale: the StayVanta Module Map and
Implementation Plan (links in the user's memory, not in the repo).

**Names below are the official ones, in the code since the naming release (step 3, 2026-10-02).**
Old addresses forward (`/dashboard` for hotel staff → `/operations`, `/revenue` → `/finance`,
`/food` → `/pos`), and the old `/api/reports/*` and `/reservations/{id}/payment` routes still
answer until they're removed a release later; never call them from new code.

### Principles
1. **One module per business area**, named with a noun (never a page type like "Dashboard" or a
   feature like "Reports").
2. **Every business process has exactly one owning module.** Others may display it or start it;
   only the owner writes its data.
3. **Overview modules only display.** Operations owns no process.
4. **Setup is separate from daily work.** Configuration belongs in Settings.
5. **Accountability is built into the data** (see "Accountability standard" below).
6. **Access is per permission and per property** (see "Permission strategy").
7. **The database is stable; names on screen evolve.** Tables, columns, stored values and role
   values (`owner`/`admin`/`receptionist`) are not renamed for naming reasons. Display names live
   in one map each (frontend `ROLE_LABELS`, nav labels in `nav.js`).

### Hub and modules
The Hub is grouped. A tile shows when its scope (platform or property) and permission match the
signed-in person (`nav.js`, `canOpen()`); empty groups are hidden.

| Group | Module | Answers / owns | In code |
|---|---|---|---|
| OVERVIEW | **Operations** | What's happening at the property now. Owns nothing. | `/operations`, `pages/Operations.jsx`, `components/operations/`, `GET /api/operations/*` |
| OVERVIEW | **Finance** | Money: collections, receivables, invoices, receipt booklets, financial analytics, expenses (future). The single home for money; no other financial module without strong justification. | `/finance`, `pages/Finance.jsx`, `components/finance/`, `GET /api/finance/*` |
| GUEST SERVICES | **Front Desk** | Reservations: book, change, cancel, check in/out | `/front-desk` |
| GUEST SERVICES | **Guests** | Guest identity and history | `/guests` |
| GUEST SERVICES | **POS** | Sales: food, linens, charges to rooms | `/pos`, `pages/Pos.jsx`; API stays `/api/food-orders`, `/api/food-menu-items` |
| PROPERTY | **Rooms** (future) | The physical room: status, housekeeping, maintenance | today: Front Desk → Rooms tab |
| PROPERTY | **Inventory** | Stock and (future) purchasing | `/inventory` (receipt booklets move to Finance in step 9) |
| TEAM | **Staff** | Accounts, roles, (future) time keeping | `/staff` |
| SETTINGS | **Settings** (future, admin-only) | Property configuration; never ships without the configuration audit log | today: Front Desk tabs and Inventory |

The platform owner has a separate, ungrouped Hub: **Dashboard** (platform overview) and
Subscribers.

### Process ownership (the only writer)
| Process | Owner | Notes |
|---|---|---|
| Reservation lifecycle | Front Desk | |
| Guest identity | Guests | Front Desk/POS create guests through Guests' duplicate check |
| Room occupancy | Front Desk (derived from reservations) | |
| Room cleanliness / out of service | Rooms (future) | Room status will split into occupancy, cleanliness and service, each with one writer. Don't add housekeeping states to `rooms.status`. |
| Sales | POS | |
| Invoices, settlement, receipt numbers | Finance | Front Desk embeds the Invoices panel for checkout; POS will stop embedding it |
| Stock levels | Inventory | Only via `StockMovementsTable::record()` |
| Prices, rates, charges, discount rules | Settings (future) | Applied by `quote()` / `StatutoryDiscount`; the menu stays in POS |
| Accounts and role assignment | Staff | Role definitions move to Settings in Permissions Phase 3 |

### Terminology (use these words on screens, in docs and in comments)
| Term | Meaning | Don't use |
|---|---|---|
| Collected | Money received: settled invoices + paid POS sales | "Revenue" on screens ("Revenue today" → **Collected today**) |
| Outstanding | Amount still on open invoices | "Not yet settled", "unsettled" |
| Receivables | The Finance tab of what's owed | "To collect" |
| Invoice | The guest's bill (the BIR Sales Invoice) | "Folio", "tab", "bill" |
| Settle | Record payment on an invoice and issue its SI/OR numbers | "Close", "pay" for invoices |
| Open / Settled | The two invoice states | |
| Reservation | A booking or stay record | Mixing "booking"/"stay" as the record's name |
| Purchase order | Always in full | "PO", "order" |
| POS sale states | Paid (collected at sale) · Charged to room (on an invoice) · Unpaid | |
| Room status | Occupied · Vacant · Reserved · Maintenance (later Clean/Dirty/Inspected, Out of service) | "Available" on screens (code value stays `available`) |
| Roles | Platform Owner (`owner`) · Manager (`admin`) · Front Desk Staff (`receptionist`) | Raw role values on screens; "Property Owner" is reserved for a future role |

**Reservation billing (live since step 3).** The old "Mark paid" didn't collect money (it posted
the room charge to the open invoice; only settling collects), "Mark unpaid" only flipped
`payment_status` and left the charge on the invoice, and a guestless stay could be "paid" with
nothing posted. So **the invoice is the source of truth** (`room_charge_invoice` →
`billing_state` on every reservation), not `payment_status`:

| Billing state | Derived from | Meaning |
|---|---|---|
| Not billed | `room_charge_invoice` null | Room charge not posted yet (check-out also posts it) |
| Billed | `room_charge_invoice` = open | Charge on the guest's invoice; amount is outstanding |
| Settled | `room_charge_invoice` = settled | Invoice settled; money collected |

"Not billed" as a *set* (Front Desk count, `?billing=not_billed`, Finance → Receivables) = a stay
that has **started** (checked in or out) with no room charge posted; future bookings aren't
receivables. Actions: **Post room charge** (`POST /reservations/{id}/post-room-charge`; refuses
with a reason when nothing can be posted: no guest, no rate, cancelled; the button is disabled
with "Add a guest first…" when no guest is attached). "Mark unpaid" is gone and the API refuses
it. **Reverse room charge** (Manager-only, reason required, removes the lines, refused once
settled) ships with the invoice event records in step 6 — never earlier, because its reason must
be stored; until then a Manager cancels the reservation to reverse its charges.
`payment_status` stays in the database and is still set on posting, but nothing reads it.

### Design review for every feature proposal
Before implementing a new feature or module, the proposal states, and the user approves:
1. **Owning module** (from the Hub table above) and **owning business process** (from the
   ownership table; a process gets exactly one owner).
2. **Required permissions**, named `module.resource.action`, including which are elevated
   (reason required) and any separation-of-duties rule.
3. **Required event records**: which `*_events` table, which event types, which require a reason.
4. **Accountability requirements**: how who / what / when / why are answered, and what is never
   overwritten or deleted.
5. **Operations impact**: new counts, alerts or cards (display only).
6. **Finance impact**: anything that changes collected, outstanding, receivables or invoices, and
   through which Finance API.

If a proposal can't answer one of these, it isn't ready to build.

### Accountability standard
Every module answers **who** did it, **what** changed, **when**, and **why** (when the action is
sensitive). This applies to reservations, invoices, POS, inventory, purchasing, maintenance,
housekeeping, expenses, time keeping and configuration.

**Today:** only inventory meets it (`stock_movements`). Known gaps: settling an invoice records
when but not who, and invoice lines have no actor; `reservations.receptionist_id` is overwritten on
every edit and transition, and reservations are hard-deleted; rate, promo, charge and menu-price
changes record nobody.

**Event recording standard** (all new ledgers; the shared foundation is build step 5):
- **Tables:** `<subject>_events` (`reservation_events`, `invoice_events`, `food_order_events`,
  `room_events`, `purchase_order_events`, `expense_events`, `time_entry_events`). Existing
  `stock_movements` keeps its name and gains the shared columns. Collections are invoice
  settlements, recorded in `invoice_events`, not in a second table. Configuration changes go to
  one shared `config_changes` table; sign-ins and permission changes to `access_events`.
- **Columns:** `property_id`, the subject id (no `ON DELETE CASCADE`), `event_type`, `actor_id`
  (NULL only when `source='system'`), `actor_role` (role at the time), `source`
  (`web`|`system`|`import`), `reason`, `changes` JSON (before/after), `snapshot` JSON (names, room,
  amounts at that moment), `correlation_id` (one per HTTP request), `occurred_at` (UTC),
  `created`. Anything filtered or summed (amounts, line ids) is a real column, never JSON.
  Index `(property_id, occurred_at)` and `(<subject>_id, id)`.
- **Event types** are past-tense snake_case string constants on the Table class
  (`ReservationEventsTable::CHECKED_IN`), not DB ENUMs. Permissions are present-tense
  `module.resource.action`. Don't mix the two styles.
- **One way in:** `<Subject>EventsTable::record(EventContext $ctx, string $type, $subject, ...)`,
  provided by a shared `EventLedgerBehavior`. `EventContext` (actor, role, property, correlation
  id, source, reason) is built once per request in `AppController`; jobs build a system context.
- **Same transaction, always:** `record()` refuses to run outside a transaction. The caller wraps
  the change and the event in one `transactional()` block, taking its `FOR UPDATE` lock first. If
  the event can't be written, the change doesn't happen.
- **Lock, then check, then change, then record (mandatory, decided 2026-10-03).** Inside the one
  transaction: (1) re-read the subject with `FOR UPDATE` (never trust an entity loaded before the
  transaction); (2) check state and rules against *that* row; (3) change it; (4) `record()` the
  event. Step 5 found two races this closes (stock computed from a stale item; a sale served or
  cancelled twice). **Required for** invoice settlement, reservation check-in, check-out and
  correction, expense approvals, purchase order approvals, and every future ledgered action.
  Each gets a test that the second of two identical requests is refused and records nothing.
- **Backfills (decided 2026-10-03)** that create events or index rows for history must be:
  **idempotent** (a second run adds nothing: skip what's already recorded, by unique key);
  **auditable** (rows marked `source = 'import'`, correlation id `import-<table>-<id>`, and a
  self-check of counts logged per property); **repeatable** (a re-runnable command beside the
  migration, batched, each batch its own transaction). **Never invent a fact the data didn't
  record:** no actor where none was stored (`actor_id` NULL, `source = 'import'`), no reason, no
  event that wasn't observed (e.g. a cancellation whose canceller is unknown isn't backfilled as one
  by someone).
- **Append-only:** event tables refuse updates and deletes (`beforeSave`/`beforeDelete`). A mistake
  is corrected by a new event that references the original. No hard deletes of business records:
  soft delete plus a `deleted` event.
- **Reasons:** each events table lists the types that require one (`REQUIRES_REASON`): delete,
  backdate, correction, cancellation after payment, discount override, void, reversal.
- **Feed:** every ledger also writes one row to `activity_index` in the same transaction;
  Operations → Activity pages that one table.
- **Configuration audit** is a `ConfigAuditBehavior` on the configuration tables (room rates,
  promo rates, extra charges, rooms, booking sources, receipt series, menu items): it diffs changed
  fields and writes `config_changes`, and refuses to save without an actor. Audit follows the data,
  not the screen, so menu prices are audited even though the menu stays in POS.
- **Step 5 (in progress) — the foundation exists:** `App\Event\EventContext`
  (`AppController::eventContext()`), `EventLedgerBehavior` + `AppendOnlyBehavior` /
  `AppendOnlyTableTrait`, `activity_index` (`ActivityIndexTable::forCorrelation()`),
  `food_order_events`, `authorizeElevated()`, and `CorrelationIdMiddleware` (one id per request,
  **mandatory** on every event, returned as `X-Request-Id` and prefixed to every server log line as
  `[req:<id>]`; deployed environments log to stderr). The event-type catalog is
  **`docs/EVENTS.md`** (`EventsCatalogTest` keeps it in step with the code). **Wired (part 2):**
  stock movements and POS sales (placed/served/cancelled) write through the foundation, so both
  meet the accountability standard. **Part 3:** the Operations feed reads `activity_index` (which
  lines, in what order), showing current values through batched lookups so it looks as before;
  history from before step 5 is indexed by `App\Event\ActivityBackfill` (migration
  `BackfillActivityIndex`; re-run with `bin/cake activity_backfill [--property N] [--check-only]`;
  its per-property check is logged). `FeedEquivalenceApiTest` keeps the old two-table merge as the
  reference the feed must match. Expected 4xx answers are logged as
  one `info` line (`App\Error\AppErrorLogger`), not as errors. Approved decisions (2026-10-03): hybrid tables,
  POS as the pilot ledger, a reason for `pos.sale.cancel_paid` (accepted-not-required until the
  cleanup release: `REASON_GRACE`), minimal snapshots (guest id + display name only) with one
  controlled redaction routine, CI should gate deployment (Railway "Wait for CI" is on but **does not
  work** yet; see below). **The activity feed becomes an
  event feed** (one line per business event, with who did it) when the invoice and reservation
  ledgers arrive; in step 5 it stays visually identical (`docs/EVENTS.md`).
- Until a module's ledger is wired, keep stamping the acting user from
  `AppController::$currentUser` on any endpoint that changes state, as today. Tests must clean
  ledger rows through the connection (`ApiScenarioTrait::LEDGER_TABLES`), since the tables refuse
  deletes.

### Permission strategy
- **Phase 1 (build step 4, no DB change) — done:** `App\Auth\Permissions` defines the 35
  `module.resource.action` constants and a fixed map from each role value to its permissions; the
  approved catalog, design rules and endpoint map are **`docs/PERMISSIONS.md`** (the source of
  truth; `PermissionsTest` keeps code and document identical). **Every API action calls
  `$this->authorize(Permissions::X)`** first (after `allowMethod()`); data-dependent checks use
  `$this->can(Permissions::X)`. Deny by default: a role the map doesn't know holds nothing.
  `userHasRole()` survives only for the staff hierarchy in `UsersController` (a test forbids it
  elsewhere). Login and `/auth/me` return `user.permissions`.
  **Frontend:** `useAuth()` gives `can(P.X)` (names in `src/auth/permissions.js`, mirrored from
  the PHP class; `PermissionsTest` fails on drift or an undefined `P.X`) and `scope`
  (`'platform'` | `'property'`). **Screens check permission and scope, never permission alone**:
  each `nav.js` item has `scope` + `permission`, and `canOpen(item, auth)` drives the Hub,
  `ProtectedRoute module="/path"` and `/dashboard`. Scope keeps the Platform Owner on platform
  screens even though they hold some hotel permissions today. Pages use `can()` for what they
  offer; the only role checks left are the staff hierarchy in `Staff.jsx` and display labels.
  A session without `user.permissions` falls back to `ROLE_FALLBACK` (remove it a release after
  Phase 1 ships). Every check on the frontend must also exist on the backend.
- **Adding an endpoint or permission:** add the catalog row and endpoint-map row to
  `docs/PERMISSIONS.md`, the constant (and grants) to `Permissions`, and a probe to
  `PermissionMatrixApiTest::PROBES` — `testEveryApiRouteHasAProbe` fails on an unmapped route.
- **Phase 2 (DB):** `roles`, `role_permissions`, `property_memberships` (user × property × role).
  A migration seeds the three presets and one membership per user; `users.role` and
  `users.property_id` stay as a fallback for one release. The platform owner becomes a platform
  flag, not a property role. The request's property is resolved from the membership.
- **Phase 3:** editable roles in Settings (audited), new starter roles (Property Owner,
  Housekeeping, Storekeeper…), multi-property switcher; drop the old user columns.
- **Rules for code written now:** resolve the property through `effectivePropertyId()` /
  `scopeToProperty()`, never `currentUser->property_id` directly (only `AppController`'s
  helpers read it). Sensitive actions
  require a reason. Nobody approves their own expense or purchase order, or corrects their own
  timesheet.

### Build order
0 decisions documented (this section) · 1 staging · 2 CI on MySQL 9 + access-control and
data-isolation tests + pinned money figures · 3 naming and navigation release (Operations, Finance,
POS, Hub groups, role labels, terminology, new `/operations` `/finance` `/platform` API beside the
old routes) · 4 permissions Phase 1 · 5 shared event foundation · 6 invoice settlement
accountability · 7 one shared collections calculation (`App\Model\Finance\Collections`) ·
8 reservation event log + soft delete · 9 Settings + configuration audit · 10 permissions Phase 2.
Deployment workflow (main → staging, `production` branch → production): `DEPLOYMENT.md` §5.
Security issues get an entry in `docs/SECURITY-FINDINGS.md` (what, how found, fix, whether
exploited); agreed follow-ups not on the build order live in `docs/BACKLOG.md`.

## Commands

### Backend (`cd backend`)
- Install: `composer install`
- Run API server: `php bin/cake.php server -p 8765` (binds to `localhost` — use
  `http://localhost:8765`, not `127.0.0.1`, or pass `-H 0.0.0.0`)
- Migrate / roll back: `php bin/cake.php migrations migrate` / `migrations rollback`
- New migration: `php bin/cake.php bake migration CreateThings` — every schema change ships its
  own dated migration on top of `config/Migrations/20260615000000_InitialSchema.php`; never edit
  the base schema. `schema-dump-default.lock` is the regenerated dump: CI makes it from the
  migrated MySQL 9 test database (the `schema-dump` artifact of a run); download and commit it when
  a migration changes the schema. Migrations must work with the previous code (new columns
  nullable first, tightened a release later).
- Seed a user: `php bin/cake.php create_user --name N --email E --password P --role owner|admin|receptionist [--property-id N]`
- Tests: `vendor/bin/phpunit` — single file: `vendor/bin/phpunit tests/TestCase/Path/ThingTest.php`;
  single test: add `--filter testName`. `composer check` = `phpunit` + `phpcs`.
- Style: `composer cs-check` / `composer cs-fix` (CakePHP standard). `phpstan.neon` and
  `psalm.xml` exist but neither tool is installed — add it to `require-dev` before running it.

**CI runs every test on every push** to `main`/`production` and on PRs (`.github/workflows/ci.yml`):
backend PHPUnit against a throwaway **MySQL 9** service (the test connection reads
`DATABASE_TEST_URL`, since CI has no `app_local.php`), plus frontend lint + build. Check results
with `gh run list` / `gh run view <id> --log-failed`. A red run blocks promotion to production.
**Staging deploys before CI finishes** (Railway's "Wait for CI" is enabled but ignored, unresolved:
`docs/BACKLOG.md`), so a failing commit still reaches staging: check CI for the exact commit before
trusting staging, and never promote one whose run isn't green.
phpcs is **not** in CI yet: the codebase has ~97 pre-existing violations; keep new and changed
files clean.

**What the tests cover** — where a wrong answer reaches a real folio, a real lock-out or another
hotel's data:
- Fixture-free unit tests (entities in memory): `ReservationsTableTest` (`quote()` pricing, incl.
  extras), `FoodOrdersTableTest` (discount/cooking-charge arithmetic), `Auth/LoginThrottleTest`,
  `Model/BusinessTimeTest` (hotel-day boundaries vs UTC), `Auth/PermissionsTest` (role map ==
  `docs/PERMISSIONS.md`; no `userHasRole()` outside the staff hierarchy).
- HTTP suites in `tests/TestCase/Controller/Api/`: **`PermissionMatrixApiTest`** (the access
  matrix: every API route × every role against the catalog, every route must have a probe, a user
  with no permissions is refused everywhere), `AccessControlApiTest` (sign-in failures,
  staff-management limits, refusal messages), `PropertyIsolationApiTest` (one hotel's staff
  can't list, open, change, book against or reference another hotel's data),
  `CollectionFiguresApiTest` (pins collected/outstanding across every report — must stay green
  through the shared finance calculation), `ReservationDiscountsApiTest`,
  `BackdatedReservationsApiTest`.
- **Every new endpoint gets a probe in the access matrix (`PermissionMatrixApiTest::PROBES`) and
  an isolation check.** New HTTP suites
  use `ApiScenarioTrait` (`createProperty()`, `makeUser()`, `callAs()`, `insertRow()`,
  `cleanupScenario()` in `tearDown()`). There are no fixtures, and the auth header must be
  re-applied before *every* request (`callAs()` does it; request config doesn't survive a request).
- Still untested: stock-movement arithmetic and invoice settlement flows (receipt numbering).

### Frontend (`cd frontend`)
- `npm run dev` (http://localhost:5173, proxies `/api` → backend) · `npm run build` ·
  `npm run preview` · `npm run lint` (ESLint). No test runner.
- **`frontend/package-lock.json` is git-ignored on purpose**: a Windows-generated lock omits
  Linux-only optional deps and breaks Cloudflare Pages' `npm ci`. Don't commit it. Node 22 is
  pinned for the Cloudflare build.

### Local MySQL
XAMPP MySQL must be running before backend commands that touch the DB (control panel, or
`C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone`).
DBs: `stay_vanta` (dev) and `stay_vanta_test` (tests), user `root` with empty password. The test
DB migrates itself: `tests/bootstrap.php` runs `Migrations\TestSuite\Migrator` every PHPUnit run
(`DB_TEST_DATABASE` / `DATABASE_TEST_URL` override the target).

**Local is MariaDB, prod is MySQL 9** (Railway image `mysql:9`, auto-updated within 9.x; CI tests
use MySQL 9 to match) — MySQL enforces `ONLY_FULL_GROUP_BY`, so a query can
pass locally and 500 on Railway. Known footgun: `$query->distinct(['col'])->count()` — use
`AppController::countDistinct($query, 'col')` (emits `COUNT(DISTINCT col)`). Prefer correlated
`EXISTS` or one query per bucket over `GROUP BY` for aggregates.

## Architecture & conventions

### The accountability model (the reason this product exists)
Every stock/asset state row and every mutating action records **who was responsible**:
- `stock_movements.receptionist_id` — NOT NULL; the append-only ledger of every in/out.
  Quantities change **only** via `StockMovementsTable::record($this->eventContext(), $item, …)`
  (transactional: locks the item row, writes the ledger row with the shared event columns and its
  `activity_index` row, updates `quantity`, stamps `inventory_items.last_receptionist_id`, rejects
  negative stock). Never mutate `inventory_items.quantity` directly.
- `reservations.receptionist_id` (stamped at creation **and** on every transition and edit, so
  it's only "last touched by", not a history), `food_orders.receptionist_id` (who placed it).

Stamp the acting user from `AppController::$currentUser` on any endpoint that changes state. The
target model, and today's gaps, are in "Accountability standard" above.

### Roles & subscriptions
Three roles on `users.role` (`UsersTable::ROLES`), shown on screens by their display names:
- **owner → Platform Owner** — the platform operator, `property_id = null`. Sees Dashboard
  (subscription revenue + counts) and Subscribers only.
- **admin → Manager** — runs one subscribing hotel. Operations (today + staff/activity),
  Inventory, Front Desk, Guests, POS, Finance (collections by day/month/range, receivables,
  invoices, analytics), Staff. Creates Front Desk Staff accounts.
- **receptionist → Front Desk Staff** — same modules minus Staff. Operations minus
  Staff/activity; on Finance, no Analytics tab and only the single-day collection report (the
  backend rejects month/range queries from them).

**Operations = the property now, Finance = money.** Operations (`pages/Operations.jsx`) is one
call, `GET /api/operations/today` (`OperationsController`), rendered by
`src/components/operations/Operations.jsx`; its only money figure is "Collected today".
Collections, Receivables (outstanding invoices + not-billed stays), Invoices and Analytics live in
Finance (`pages/Finance.jsx`, `components/finance/`, `FinanceController`) — keep them off
Operations. The Platform Owner's Dashboard is `pages/PlatformDashboard.jsx` /
`PlatformController`. The "Needs attention" panel is **computed**
from live counts (no stored notification/read state) and operational only. "Reserved" rooms are
derived (an available room held by a `booked` reservation covering today), not a room status.

Nav visibility lives in `src/nav.js` (`scope` + `permission` per item, see "Permission strategy")
and `ProtectedRoute module=` in `App.jsx`, but those are UX only — **every check must also exist
on the backend**. `UsersController`
is the canonical example (`findManageable()` confines admins to their property and excludes
owners; deactivation blocks login; reset-password nulls `api_token`). Owner/admin-only writes
are the norm across modules; see `backend/API.md` for which endpoint allows what.

Each `properties` row is a subscribing hotel/resort; `Property::subscription_active` (status
`active` AND not past `subscription_expires_at`) is the source of truth. Owner revenue is
`subscription_fee` projected (week = MRR·12/52, month = MRR, YTD = MRR·months-elapsed).

### Backend request flow
- API controllers live in `src/Controller/Api/`, extend `Api\AppController`, routed by a
  `prefix('Api', ['path' => '/api'])` scope. `beforeFilter` forces JSON and does **bearer-token
  auth** (`Authorization: Bearer <token>` → `$this->currentUser`); list auth-free actions in
  `$publicActions`.
- **API errors are JSON** (`ErrorController::beforeRender` → `{message, code, url}`): throw
  `BadRequestException`/`ForbiddenException`/etc. with a clear message and the SPA shows it
  (`err.response.data.message`).
- CSRF is skipped for `/api` (`Application::csrfMiddleware()`). `App\Middleware\CorsMiddleware`
  reflects all origins in debug, else uses `App.corsOrigins`, and answers preflight `OPTIONS`
  directly with 204 (routing it to a controller would 405).
- `FactoryLocator` fallback tables are **disabled**: every table you reference (incl. via
  associations) needs a real `*Table` class in `src/Model/Table/`.
- Inside a `Table` subclass, get other tables via `TableRegistry::getTableLocator()->get()`
  (`Table` has no `getTableLocator()` in CakePHP 5).
- Concurrency idiom: take a `FOR UPDATE` lock on the relevant row inside the transaction before a
  check-then-write (`ReceiptSeriesTable::assignNext()`, `ReservationsTable::lockRoom()`,
  `postRoomCharge()`).
- Soft-deletes (`deleted_at`) on inventory items and menu items, so ledgers/order history survive.

### Auth is foundation-level, not production-grade
Login issues an opaque 256-bit token (30-day expiry); `users.api_token` stores only its SHA-256
digest (`UsersTable::issueToken`/`hashToken`) — a plain hash is deliberate, the token is already
random. Passwords use `password_hash`/`password_verify` (`User::_setPassword`). For production,
migrate to `cakephp/authentication`.

`App\Auth\LoginThrottle` pauses an **email address** after 5 failed sign-ins for 900 s; counters
live in the `login_throttle` cache config, whose `duration` must match `LOCKOUT_SECONDS`.
Deliberately **not keyed on IP**: no trusted proxy is configured, so `clientIp()` is Railway's
proxy for everyone (an IP limit would lock out every property), and trusting `X-Forwarded-For`
would make the key attacker-controlled.

### Frontend structure
- `src/api/client.js` is the single axios instance (token from localStorage `stayvanta_token`;
  base URL `VITE_API_BASE_URL` or `/api`). **Pages never call axios directly**: each module has a
  thin wrapper (`inventory.js`, `frontdesk.js`, `guests.js`, `food.js`, `operations.js`, `finance.js`,
  `platform.js`, `staff.js`)
  with one function per endpoint unwrapping `r.data.<key>`; owners pass `propertyId` through its
  local `withProp()` helper. Add new calls there.
- `AuthContext` (`useAuth()` → `{user, role, loading, login, logout}`, resolves the token via
  `/auth/me` on boot); `PropertyContext` (`useProperty()` → `propertyId`: staff are bound to
  theirs, an owner's choice persists in localStorage). Helpers: `src/hooks/useSubmit.js` (submit +
  CakePHP validation-error extraction), `src/utils/format.js` (`formatMoney`, PHP peso).
- **No persistent tab nav, on purpose.** `/hub` (`Hub.jsx`) is a role-scoped tile grid and the
  post-login landing; `Layout.jsx`'s header holds only the brand mark (link to `/hub`), theme
  toggle and user chip. Every module switch goes back through the Hub; pages render a
  `Home / <module>` breadcrumb at the top of the body, labels read from `src/nav.js` (the single
  source of truth for modules; icons are hand-rolled SVGs in `src/components/icons.jsx`). Don't
  add a tab bar back into `Layout.jsx`.
- **Public routes** (no auth, no Layout): `/` → `Landing.jsx`, `/login`, `/privacy`, `/terms`.
  `RootRoute` redirects a signed-in user to `/hub` and renders nothing while `useAuth().loading`
  resolves (avoids flashing marketing copy). Hotel staff land on `/operations`; `/dashboard` is the
  Platform Owner's (`DashboardRoute` forwards anyone else to `/operations`).
- **`Landing.jsx` is deliberately outside the design system** (hard-coded dark promo palette,
  Manrope, no `ui.jsx`). Don't "fix" it to follow the theme. It shares only `BrandMark.jsx` /
  `BrandSplash.jsx` with the app.
- **Theming is token-driven**: `index.css` redefines every token under `:root[data-theme='dark']`;
  `ThemeContext` writes `data-theme` on `<html>` (stored under `stayvanta_theme`, otherwise follows
  the OS), and `dark:` is bound to that attribute via `@custom-variant`. Use `text-on-ink` (not
  `text-white`) on `bg-ink`/`bg-accent`, and give hand-written status colors an explicit `dark:`
  pair. Three places duplicate values and must be kept in sync: `index.html`'s pre-paint script
  (storage key + splash colors), `BrandSplash.jsx`, and `components/finance/SeasonalityChart.jsx`'s `CHART_COLORS`
  (ApexCharts can't read CSS variables). Use the `frontend-design` skill for UI work.
- **API errors go through `describeError(ex, fallback)`** (`src/utils/apiError.js`): it returns the
  message (first validation error, else the API's message, else the fallback) and the request's
  `X-Request-Id` (kept on the error by the `client.js` interceptor). Store that in error state and
  render it as `<Alert variant="danger">{error}</Alert>`: `Alert` shows the message plus
  "Reference: 8c2a236e" with a Copy button. Don't read `ex.response.data.message` by hand; if the
  error goes inside other text, use `error.message`. `useSubmit` already returns one.
- **Elevated actions ask why with `src/components/ReasonModal.jsx`** (`show`, `title`,
  `description`, `confirmLabel`, `onConfirm(reason)`, `onHide`) and send `reason` with the request.
- Initial loads use skeletons from `src/components/Skeleton.jsx`, not spinners (inline action
  buttons keep their small spinner). Stat tiles go through `src/components/StatCard.jsx` — extend
  it rather than hand-rolling a card + number (copies in pages drifted before). When a row of tiles
  gets crowded, group figures that answer one question with its `SummaryGroup` + `SummaryRow`
  (Front Desk: Rooms / Today / Receivables); a `SummaryRow` with `onClick` jumps to the list
  behind the number — `Tabs` takes react-bootstrap-style `activeKey`/`onSelect` for that.
- The **Invoices** tab (list, folio view, Settle with SI/OR booklet numbers) is one component,
  `src/components/finance/InvoicesPanel.jsx`, rendered by Front Desk (checkout) and Finance —
  change it there, not in a page. It loads itself when its tab opens (`Tabs` mounts only the
  active tab); `onSettled` lets the host refresh what depends on settlement.

### Configuration & deployment (see `DEPLOYMENT.md`)
- Prod config is env-driven in the committed `config/app.php` (`DATABASE_URL` or `DB_*`,
  `SECURITY_SALT`, `CORS_ORIGINS`), so prod needs no `app_local.php`. `config/app_local.php` is
  git-ignored (XAMPP defaults) and excluded from the Docker image. Vars are documented in
  `backend/config/.env.example` and `frontend/.env.example`.
- Frontend: Cloudflare Pages (root `frontend`, output `dist`, `public/_redirects` = SPA fallback,
  `VITE_API_BASE_URL` at build time).
- Backend: Railway via `backend/Dockerfile` (Apache, docroot `webroot`); `docker/entrypoint.sh`
  binds `$PORT` and runs `migrations migrate` on start. Required env: `DATABASE_URL`,
  `SECURITY_SALT`, `DEBUG=false`, `APP_FULL_BASE_URL` (HostHeaderMiddleware blocks requests
  without it), `CORS_ORIGINS`. `*.sh`/`Dockerfile` are pinned to LF in `.gitattributes`.
- **Release workflow (live since 2026-10-02):** pushing `main` deploys **staging** (API
  `https://stay-vanta-staging.up.railway.app`, frontend `https://main.stay-vanta.pages.dev`);
  production (`https://stay-vanta-production.up.railway.app`, `https://stay-vanta.pages.dev`) only
  deploys from the `production` branch, promoted with `git push origin main:production` after
  verifying on staging. API changes are additive first (the two apps deploy independently) and
  migrations must work with the previous code (rollback restores code, not data). Database URLs are
  Railway **references** (`${{MySQL.MYSQL_PUBLIC_URL}}`), never literals, so each environment
  reaches its own database. Details: `DEPLOYMENT.md` §5.

## Domain rules

### Money: revenue, invoices, receipts
- **Hotel revenue = collected**: Σ settled `invoices.total` (by `settled_at`, stamped in
  `InvoicesController::settle`) + Σ `paid` `food_orders.total` (by `created`). Charge-to-room food
  already lives inside invoices, so only `paid` orders are added. **Posting a room charge is not
  collecting it**: it puts the charge on the guest's *open* invoice (the stay reads **Billed**),
  which counts only once settled (**Settled**; Settle is where SI/OR booklet numbers are assigned —
  deliberately kept). So reports also return `outstanding` (Σ open invoices, shown as
  "Outstanding"), and every reservation carries `billing_state` derived from
  `room_charge_invoice`.
- **Days are the hotel's, not UTC's.** Timestamps are stored UTC, but "today", daily/weekly/monthly
  windows and every day filter go through `App\Model\BusinessTime` (`App.businessTimezone`, env
  `APP_BUSINESS_TIMEZONE`, default Asia/Manila): `startOf()`/`endOf()` for datetime bounds,
  `today()` for comparing date columns. Never `Date::today()`/`date('Y-m-d')` for business dates.
  The frontend's `todayStr()` uses `toLocaleDateString('en-CA')` (local), never `toISOString()`.
- **Invoices change only through `InvoicesTable`** (build step 6), every method taking the request's
  `EventContext` and recording to `invoice_events` under a `FOR UPDATE` lock: `openInvoiceFor()`,
  `addLine()`, `reverseLinesFor()` / `reverseLine()`, `settle()`, `settledInvoiceWith()`,
  `recordRefund()`. **Lines are never deleted**: a cancelled line gets a negative line with
  `reverses_line_id`, and the total is the sum of all lines. "Is it posted?" checks
  (`invoiceForLine()`, not-billed, billing state) use `InvoiceLinesTable::find('active')`.
  **Settled invoices never change** (refused with a 400), except `recordRefund()` for a downpayment
  refund (on step 7's agenda). Settling is idempotent. Manual reversal:
  `POST /invoices/{id}/lines/{lineId}/reverse` (`finance.invoice.reverse`, Manager, reason).
  Catalog: `docs/EVENTS.md`. **Discounts are always itemized as their own negative lines** naming who got
  them, never folded into a net figure. VAT (12%, already included in prices) is derived for
  display only in `Pos.jsx`'s `vatBreakdown()`; the stored total is unchanged.
- **Receipt series** (`receipt_series`, managed on Inventory → Receipt Booklets) register
  pre-printed Sales Invoice / Official Receipt booklets. `ReceiptSeriesTable::assignNext()`
  consumes the next number from the oldest active, non-exhausted series (`FOR UPDATE`), padded to
  `pad_length`. A series that has issued a number can be deactivated, not deleted.

### Statutory Senior/PWD discount (shared by Front Desk and POS)
One rule over two bills, in **`App\Model\StatutoryDiscount`** — don't write a second copy. The 20%
covers only each beneficiary's own even share (RA 9994): `subtotal × (beneficiaries / people) ×
20%`, where people = `reservations.total_guests` or `food_orders.total_diners`. Beneficiaries can
never outnumber people. The total is split in whole cents, leftover cents to the earliest
beneficiaries. Each beneficiary (`discount_type` senior|pwd, name, ID) gets its own invoice line.
- Food: `food_order_discounts` rows **snapshot `amount`** (an order is a finished transaction).
- Reservations: `reservation_discounts` rows carry **no amount** — a booking is re-quoted live, so
  the invoice line is the only snapshot. `quote()` loads the rows itself if the caller didn't
  `contain()` them (`beneficiariesFor()`), so it can't silently bill full rate.

### Front Desk (reservations)
- **Pricing is `ReservationsTable::quote()`**: nightly rate = `promo_rate` ?? resolved room rate
  (`resolveBaseRate()`: room-specific `room_rates` row, else cheapest property-wide). Order of
  deductions: **channel discount first** (percent, or fixed capped at subtotal), then the statutory
  share, then the **referral** (`discount_amount`, flat pesos, optional but `> 0` if set, capped so
  the total can't go negative). Statutory and referral stack. Then the booking's **extra
  charges** (`reservation_extra_charges`, admin-configured custom charges × quantity, name/amount
  snapshotted at pick time) are added on top — never discounted. `quote()` returns each component
  separately so `postRoomCharge()` can itemize them; extras post as `reservation`-sourced lines, so
  idempotency, cancel reversal and the delete guard cover them for free. (`reservations.additional_beds`
  is a legacy unpriced count, no longer on the form — an extra bed is an extra charge.)
- **`promo_rate` is never client-supplied**: `promo_rates` holds an admin-set `multiplier` per
  booking source (room-specific wins, `PromoRatesTable::multiplierFor()`); add/edit stamp
  base × multiplier server-side.
- **Booking sources** are per-property `booking_sources` rows with no management UI — created only
  as a side effect of promo-rate writes (`BookingSourcesTable::resolveOrCreate()`). `code` is
  slugified once and never changes (it's what `reservations.source` stores). `walk_in` is a
  constant (`BookingSourcesTable::WALK_IN`), not a row. Don't seed default sources.
- **Walk-in vs online**: `add()` decides from `source`, not the form. A walk-in forces `check_in` to
  today, saves `checked_in`, flips the room to `occupied` — no early check-in fee, no downpayment,
  and `booking_reference`/`sold_rate`/channel discount nulled. An online booking is saved `booked`,
  **requires `booking_reference`** (build rule), and may record `sold_rate` — which is
  **recorded, never priced** (only for reconciling the OTA remittance).
- **Past stays are admin-only**: a check-in before today (`ReservationsController::isBackdated()`)
  is refused for anyone but `admin` — except a receptionist's walk-in, whose date is simply forced
  to today. Its status follows the dates (ended on/before today → `checked_out`, room untouched;
  still going → `checked_in`, room occupied), and `checked_in_at`/`checked_out_at` are the stay's
  own dates so it doesn't count toward today's activity. No downpayment; collected once its room
  charge is posted and the invoice settled, as usual. Moving an existing booking's check-in into the past is admin-only too.
- **No double-booking**: the `roomAvailable` build rule rejects overlap on `[check_in, check_out)`
  among `HOLDS_ROOM` statuses — plus `checked_out` when a past stay is being entered (check-out
  day is free for a same-day check-in; the Calendar tab derives availability the same way).
  `add()`/`edit()` call `lockRoom()` first. `ReservationsTable::conflicting()` queries the overlap.
- **Lifecycle**: transitions are guarded by an allowed-from-state table and flip `rooms.status`.
  A `booked` reservation is editable (row click reopens `ReservationModal`, booking fields only)
  until check-in, and not at all once a downpayment was collected — cancel and rebook. An
  **admin** can also open a checked-in/out row to correct it (until its room charge is posted;
  `correctStay()` keeps the dates consistent with the status) or **delete** it — refused by
  `transactionOn()` once anything was transacted (downpayment, invoice/lines, or the guest's food
  orders during the stay, matched by guest + date since orders carry no `reservation_id`).
  **Once the room charge's invoice is settled (`isSettled()`), a reservation of any status is
  locked**: no edit, no delete — backend-enforced; its Billing badge reads Settled. Every row
  still opens `ReservationModal`: when
  `editBlockReason()` (settled / cancelled / a stay a non-admin can't change) returns a reason, it
  opens as a **read-only view** — the form inside a disabled `<fieldset>`, only a Close button.
- **Downpayment**: an advance booking (check-in after today, guest on file) collects 50% as an
  immediately-settled invoice (`InvoicesTable::settledInvoiceWith`), so it counts as collected that
  day. `collectAdvanceDownpayment()` is shared by `add()` and `edit()`. Cancel from `booked`
  appends a negative `downpayment_refund` (90%), retaining 10%.
- **Room charge**: `ReservationsController::postRoomCharge()` posts the itemized `quote()` onto
  the guest's invoice. It's called by Post room charge (`postCharge()`, via `postChargeOrFail()`)
  and by check-out, and is idempotent
  (`invoiceForLine('reservation', …)`), so whichever fires first posts it. It posts the offsetting
  `downpayment_credit` in the same call, **only once the charge line exists** (so an unresolvable
  rate can't strand a credit), under a `FOR UPDATE` lock on the reservation. Cancel reverses the
  room charge, credit and early check-in fee. `payment_status` is still written but nothing reads
  it; billing comes from `billing_state` (see "Reservation billing").
- **Early check-in**: a built-in, non-deletable `extra_charges` row (`early_check_in`, seeded by
  `ExtraChargesTable::earlyCheckInFor()`). Checking in before noon prompts, then posts
  `early_check_in:true` → fee billed to the invoice. Fee 0 disables it.
- Booking can create a guest inline or reuse one (`guest_id`); `completeGuest()` fills only the
  guest's empty fields, never overwrites.

- **The Reservations tab is paginated server-side** (25/page; the Today/This week/All window is
  the `since` param), so nothing on `FrontDesk.jsx` may be derived from that page: the summary
  cards come from `/reservations/stats` and the Calendar from `?on_date=`, each fetched on its own.
  The summary is three grouped cards — Rooms (occupancy bar), Today, Receivables (ringed when
  anything's owed; "not billed" filters the table via `?billing=not_billed`, "outstanding
  invoices" opens the Invoices tab).

### POS (food & orders; code and API keep the `food` names)
- **`FoodOrdersTable::place()` is the orchestrator**, one transaction: saves order + lines,
  decrements stock for the linked item, every recipe ingredient (per-serving qty × ordered qty) and
  picked options via `StockMovementsTable::record()` (short stock rolls back the whole order), and
  for `charge_to_room` appends lines to the guest's open invoice. `cancelOrder()` reverses both.
- `total = subtotal − statutory discount + cooking_charge`; `cooking_charge` is a service fee
  added after the discount. Custom lines (`food_menu_item_id` null) are part of the subtotal but
  never touch stock. `payment_method` is required iff `payment_status` is `paid`.
- Menu items: a single optional `inventory_item_id` link, plus an optional recipe
  (`food_menu_item_ingredients`), plus guest-facing **option groups** — `choice` (exactly one pick
  required) or `addon` (any number). An option's price/stock effect applies **once per order line,
  not × the line's quantity**. Picks are snapshotted into `food_order_item_options` (like
  `unit_price` on `food_order_items`), so cancel/restock never reads live config. An item whose
  stock or any ingredient is short is force-saved unavailable (`resolveAvailability()`).
- `food_menu_items.type` (`food`|`linen`) splits management into Food and Linens tabs, each
  scoping its Linked Stock picker to the matching category `kind`; both are orderable together.

### Inventory
- Categories (Food Stocks → Drinks, Hygiene Kit, Linens, Utensils) nest via
  `inventory_categories.parent_id`; `kind` tags the type (`food_stock`, `linen`, …).
- `tracking_type` `consumable` (In/Out) or `reusable`: for reusables `quantity` = on the shelf,
  `total_quantity` = owned; `record()`'s `$affectsTotal` moves the owned total (acquire/retire)
  vs. only the available count (issue/return), and blocks returning more than owned.
- Consumables may nest one level via `inventory_items.parent_id` (sub-items with their own stock).

### Guests
`GuestsController::stats`: total/local/foreign count only guests **registered today** (the cards
reset daily); `in_house` is current. `POST /api/guests` returns 409 + `duplicates` on a
look-alike unless `force`.
