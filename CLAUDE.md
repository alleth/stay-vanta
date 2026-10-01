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
  `apexcharts`/`react-apexcharts` for the Revenue page's seasonality chart, loaded via `React.lazy()`
  (~200KB gzipped) so only an admin who opens that chart pays for it.
- `backend/` — **CakePHP 5** JSON REST API. **MySQL** (XAMPP locally, Railway in prod).
  Full per-endpoint contract: **`backend/API.md`** — update it when you add or change an endpoint.

Frontend → Cloudflare Pages; backend → Railway. Different origins in production (CORS), same
origin in dev (Vite proxy).

## Commands

### Backend (`cd backend`)
- Install: `composer install`
- Run API server: `php bin/cake.php server -p 8765` (binds to `localhost` — use
  `http://localhost:8765`, not `127.0.0.1`, or pass `-H 0.0.0.0`)
- Migrate / roll back: `php bin/cake.php migrations migrate` / `migrations rollback`
- New migration: `php bin/cake.php bake migration CreateThings` — every schema change ships its
  own dated migration on top of `config/Migrations/20260615000000_InitialSchema.php`; never edit
  the base schema. `schema-dump-default.lock` is the regenerated dump.
- Seed a user: `php bin/cake.php create_user --name N --email E --password P --role owner|admin|receptionist [--property-id N]`
- Tests: `vendor/bin/phpunit` — single file: `vendor/bin/phpunit tests/TestCase/Path/ThingTest.php`;
  single test: add `--filter testName`. `composer check` = `phpunit` + `phpcs`.
- Style: `composer cs-check` / `composer cs-fix` (CakePHP standard). `phpstan.neon` and
  `psalm.xml` exist but neither tool is installed — add it to `require-dev` before running it.

**Test coverage is deliberately narrow**: only where a wrong number reaches a real folio or a
real lock-out — `ReservationsTableTest` (`quote()` pricing, incl. extras), `FoodOrdersTableTest`
(discount/cooking-charge arithmetic), `Auth/LoginThrottleTest`, `Model/BusinessTimeTest`
(hotel-day boundaries vs UTC) — all fixture-free, entities built in memory — plus two HTTP-level
suites: `Controller/Api/ReservationDiscountsApiTest` and `BackdatedReservationsApiTest`
(admin-only past stays, stay edits and delete). **There are no fixtures**: the HTTP suites each
build their own property/room/rate/user in `setUp()`, remove them in
`tearDown()`, and re-apply the auth header before *every* request (request config doesn't
survive a request — the second call otherwise 401s). Other role enforcement, stock movements and
invoice settlement are untested, so a green run doesn't validate the API.

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

**Local is MariaDB, prod is MySQL 8** — MySQL 8 enforces `ONLY_FULL_GROUP_BY`, so a query can
pass locally and 500 on Railway. Known footgun: `$query->distinct(['col'])->count()` — use
`AppController::countDistinct($query, 'col')` (emits `COUNT(DISTINCT col)`). Prefer correlated
`EXISTS` or one query per bucket over `GROUP BY` for aggregates.

## Architecture & conventions

### The accountability model (the reason this product exists)
Every stock/asset state row and every mutating action records **who was responsible**:
- `stock_movements.receptionist_id` — NOT NULL; the append-only ledger of every in/out.
  Quantities change **only** via `StockMovementsTable::record()` (transactional: writes the ledger
  row, updates `quantity`, stamps `inventory_items.last_receptionist_id`, rejects negative stock).
  Never mutate `inventory_items.quantity` directly.
- `reservations.receptionist_id` (stamped at creation **and** on every transition),
  `food_orders.receptionist_id`.

Stamp the acting user from `AppController::$currentUser` on any endpoint that changes state.

### Roles & subscriptions
Three roles on `users.role` (`UsersTable::ROLES`):
- **owner** — the platform operator, `property_id = null`. Sees Dashboard (subscription revenue
  + counts) and Subscribers only.
- **admin** — a subscribing hotel's head. Dashboard (today's operations + staff/activity),
  Inventory, Front Desk, Guests, Food & Orders, Revenue (collections by day/month/range, to
  collect, invoices, analytics), Staff. Creates receptionists.
- **receptionist** — same modules minus Staff. Dashboard minus Staff/activity; on Revenue, no
  Analytics tab and only the single-day collection report (the backend rejects month/range queries
  from them).

**Dashboard = operations, Revenue = money.** The admin/receptionist Dashboard is one call,
`GET /reports/operations`, rendered by `src/components/Operations.jsx`; its only money figure is
"Revenue today" (collected today). Collections, what's owed (open invoices, unpaid reservations),
invoices and revenue analytics live on `src/pages/Revenue.jsx` — keep them off the Dashboard. The
"Needs attention" panel is **computed** from live counts (no stored notification/read state) and
operational only. "Reserved" rooms are derived (an available room held by a `booked` stay
covering today), not a room status.

Nav visibility lives in `src/nav.js` (`roles` per item) and `ProtectedRoute roles=` in `App.jsx`,
but those are UX only — **every role check must also exist on the backend**. `UsersController`
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
  thin wrapper (`inventory.js`, `frontdesk.js`, `guests.js`, `food.js`, `reports.js`, `staff.js`)
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
  resolves (avoids flashing marketing copy). Dashboard is `/dashboard`.
- **`Landing.jsx` is deliberately outside the design system** (hard-coded dark promo palette,
  Manrope, no `ui.jsx`). Don't "fix" it to follow the theme. It shares only `BrandMark.jsx` /
  `BrandSplash.jsx` with the app.
- **Theming is token-driven**: `index.css` redefines every token under `:root[data-theme='dark']`;
  `ThemeContext` writes `data-theme` on `<html>` (stored under `stayvanta_theme`, otherwise follows
  the OS), and `dark:` is bound to that attribute via `@custom-variant`. Use `text-on-ink` (not
  `text-white`) on `bg-ink`/`bg-accent`, and give hand-written status colors an explicit `dark:`
  pair. Three places duplicate values and must be kept in sync: `index.html`'s pre-paint script
  (storage key + splash colors), `BrandSplash.jsx`, and `Revenue.jsx`'s `CHART_COLORS`
  (ApexCharts can't read CSS variables). Use the `frontend-design` skill for UI work.
- Initial loads use skeletons from `src/components/Skeleton.jsx`, not spinners (inline action
  buttons keep their small spinner). Stat tiles go through `src/components/StatCard.jsx` — extend
  it rather than hand-rolling a card + number (copies in pages drifted before). When a row of tiles
  gets crowded, group figures that answer one question with its `SummaryGroup` + `SummaryRow`
  (Front Desk: Rooms / Today / To collect); a `SummaryRow` with `onClick` jumps to the list
  behind the number — `Tabs` takes react-bootstrap-style `activeKey`/`onSelect` for that.
- The **Invoices** tab (list, folio view, Settle with SI/OR booklet numbers) is one component,
  `src/components/Invoices.jsx` (`InvoicesPanel`), rendered by Food & Orders, Front Desk and Revenue —
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

## Domain rules

### Money: revenue, invoices, receipts
- **Hotel revenue = collected**: Σ settled `invoices.total` (by `settled_at`, stamped in
  `InvoicesController::settle`) + Σ `paid` `food_orders.total` (by `created`). Charge-to-room food
  already lives inside invoices, so only `paid` orders are added. **Marking a reservation paid is
  not collecting it**: it posts the room charge to the guest's *open* invoice, which counts only
  once settled (Settle is where SI/OR booklet numbers are assigned — deliberately kept). So reports
  also return `outstanding` (Σ open invoices), shown as "Not yet settled" on Revenue → Collections, and
  the Reservations table flags "invoice not settled" (`room_charge_invoice`).
- **Days are the hotel's, not UTC's.** Timestamps are stored UTC, but "today", daily/weekly/monthly
  windows and every day filter go through `App\Model\BusinessTime` (`App.businessTimezone`, env
  `APP_BUSINESS_TIMEZONE`, default Asia/Manila): `startOf()`/`endOf()` for datetime bounds,
  `today()` for comparing date columns. Never `Date::today()`/`date('Y-m-d')` for business dates.
  The frontend's `todayStr()` uses `toLocaleDateString('en-CA')` (local), never `toISOString()`.
- Invoice lines go through `InvoicesTable` (`openInvoiceFor()`, `addLine()`, `invoiceForLine()`,
  `removeLinesFor()` — which recomputes the total from remaining lines, so multi-line reversals are
  order-independent). **Discounts are always itemized as their own negative lines** naming who got
  them, never folded into a net figure. VAT (12%, already included in prices) is derived for
  display only in `Food.jsx`'s `vatBreakdown()`; the stored total is unchanged.
- **Receipt series** (`receipt_series`, managed on Inventory → Receipt Booklets) register
  pre-printed Sales Invoice / Official Receipt booklets. `ReceiptSeriesTable::assignNext()`
  consumes the next number from the oldest active, non-exhausted series (`FOR UPDATE`), padded to
  `pad_length`. A series that has issued a number can be deactivated, not deleted.

### Statutory Senior/PWD discount (shared by Front Desk and Food)
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
  own dates so it doesn't count toward today's activity. No downpayment; revenue comes from Mark
  paid as usual. Moving an existing booking's check-in into the past is admin-only too.
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
  locked**: no edit, no delete, no Mark unpaid — backend-enforced; the table hides Mark unpaid
  and shows "invoice settled". Every row still opens `ReservationModal`: when
  `editBlockReason()` (settled / cancelled / a stay a non-admin can't change) returns a reason, it
  opens as a **read-only view** — the form inside a disabled `<fieldset>`, only a Close button.
- **Downpayment**: an advance booking (check-in after today, guest on file) collects 50% as an
  immediately-settled invoice (`InvoicesTable::settledInvoiceWith`), so it counts as collected that
  day. `collectAdvanceDownpayment()` is shared by `add()` and `edit()`. Cancel from `booked`
  appends a negative `downpayment_refund` (90%), retaining 10%.
- **Room charge**: `ReservationsController::postRoomCharge()` posts the itemized `quote()` onto
  the guest's invoice. It's called by Mark paid (`payment()`) and by check-out, and is idempotent
  (`invoiceForLine('reservation', …)`), so whichever fires first posts it. It posts the offsetting
  `downpayment_credit` in the same call, **only once the charge line exists** (so an unresolvable
  rate can't strand a credit), under a `FOR UPDATE` lock on the reservation. Cancel reverses the
  room charge, credit and early check-in fee. `payment_status` is an operational flag independent
  of `status` and of invoice settlement.
- **Early check-in**: a built-in, non-deletable `extra_charges` row (`early_check_in`, seeded by
  `ExtraChargesTable::earlyCheckInFor()`). Checking in before noon prompts, then posts
  `early_check_in:true` → fee billed to the invoice. Fee 0 disables it.
- Booking can create a guest inline or reuse one (`guest_id`); `completeGuest()` fills only the
  guest's empty fields, never overwrites.

- **The Reservations tab is paginated server-side** (25/page; the Today/This week/All window is
  the `since` param), so nothing on `FrontDesk.jsx` may be derived from that page: the summary
  cards come from `/reservations/stats` and the Calendar from `?on_date=`, each fetched on its own.
  The summary is three grouped cards — Rooms (occupancy bar), Today, To collect (ringed when
  anything's owed; "unpaid" filters the table via `?payment_status=unpaid`, "unsettled invoices"
  opens the Invoices tab).

### Food & Orders
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
