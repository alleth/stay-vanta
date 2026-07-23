# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

StayVanta — "All-in-One Hotel & Resort Management Platform". A monorepo of two
independently-deployed apps that talk over a JSON API:

- `frontend/` — React 19 SPA (Vite). Styling is **pure Tailwind v4** (no Bootstrap):
  design tokens live in `src/index.css` `@theme` (so `text-muted`, `bg-subtle`,
  `border-line`, `bg-ink`, `text-accent`… are utilities), and a small in-house kit
  `src/components/ui.jsx` provides Button/Badge/Card/Table/Modal/Form/Alert/Tabs/etc.
  with react-bootstrap-style APIs (`variant`, `size`, `show`/`onHide`). Routing via
  react-router-dom v7, HTTP via axios.
- `backend/` — **CakePHP 5** JSON REST API. **MySQL** (XAMPP locally, Railway in prod).

Frontend deploys to **Cloudflare Pages**; the backend API deploys separately. They are
different origins in production (CORS), same origin in dev (Vite proxy).

## Commands

### Backend (`cd backend`)
- Install: `composer install`
- Run API server: `php bin/cake.php server -p 8765` (binds to `localhost` — use
  `http://localhost:8765`, not `127.0.0.1`, or pass `-H 0.0.0.0`)
- Migrate DB: `php bin/cake.php migrations migrate`
- Roll back: `php bin/cake.php migrations rollback`
- Create a migration: `php bin/cake.php bake migration CreateThings`
- Seed a user: `php bin/cake.php create_user --name N --email E --password P --role owner|admin|receptionist [--property-id N]`
- Tests: `vendor/bin/phpunit` — single file: `vendor/bin/phpunit tests/TestCase/Path/ThingTest.php`.
  The suite is still the CakePHP skeleton's (`ApplicationTest`, `PagesControllerTest`) — no
  domain logic is covered; don't assume a green run validates business rules.
- Style: `composer cs-check` / `composer cs-fix` (phpcs / phpcbf, CakePHP standard). A
  `phpstan.neon` exists but PHPStan is only a `suggest` (not installed / no `composer stan`
  script) — run it only after adding `phpstan/phpstan` to `require-dev`.

### Frontend (`cd frontend`)
- Dev server: `npm run dev` (http://localhost:5173, proxies `/api` → backend)
- Build: `npm run build` · Preview build: `npm run preview`
- Lint: `npm run lint` (ESLint)
- **`frontend/package-lock.json` is git-ignored on purpose** (see `frontend/.gitignore`):
  a Windows-generated lock omits Linux-only optional deps and breaks Cloudflare Pages'
  (Linux) `npm ci`. Don't commit it; Cloudflare resolves deps from `package.json` instead.
  Node 22 is pinned for the Cloudflare build.

### Local MySQL
XAMPP MySQL must be running before backend commands that touch the DB. Start it via the
XAMPP control panel, or directly: `C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone`.
Create DBs: `stay_vanta` (dev) and `stay_vanta_test` (tests). Default local creds are
`root` with empty password.

**Local is MariaDB, prod is MySQL 8** — MySQL 8 enforces `ONLY_FULL_GROUP_BY` by default,
XAMPP's MariaDB doesn't, so a query can pass locally and 500 on Railway. Known footgun:
`$query->distinct(['col'])->count()` (emits `GROUP BY` while selecting all columns) — use
`AppController::countDistinct($query, 'col')` instead, which emits `COUNT(DISTINCT col)`.

## Architecture & conventions

### The accountability model (the reason this product exists)
Every stock/asset state row and every mutating action carries a **`receptionist_id`** so
the platform can always answer "who was responsible at the time":
- `inventory_items.last_receptionist_id` — last receptionist who moved the item.
- `stock_movements.receptionist_id` — NOT NULL; this table is the append-only ledger of
  every in/out. Write a `stock_movements` row for **every** quantity change; don't mutate
  `inventory_items.quantity` silently.
- `reservations.receptionist_id`, `food_orders.receptionist_id` — who performed the action.

When building any endpoint that changes inventory/asset state, stamp the acting user from
`AppController::$currentUser` (the authenticated receptionist) onto these columns.

### Roles
Three roles on `users.role`: `owner` | `admin` | `receptionist` (see `UsersTable::ROLES`).
`owner` has `property_id = null`; `admin`/`receptionist` belong to a property. The React
side mirrors this in `Layout.jsx` (nav visibility) and `ProtectedRoute` (`roles` prop).
Enforce role checks on the **backend** too — frontend guards are UX only.

### Backend request flow
- All API controllers live in `src/Controller/Api/` and extend `Api\AppController`.
- `Api\AppController::beforeFilter` forces JSON responses and does **bearer-token auth**:
  it reads `Authorization: Bearer <token>`, matches `users.api_token`, and exposes the user
  as `$this->currentUser`. List actions that should skip auth in a controller's
  `$publicActions` (e.g. `login`).
- **API errors are JSON**: `ErrorController::beforeRender` renders `/api` exceptions as
  `{message, code, url}` (not the HTML error page), so the SPA can surface the real reason.
  Throw `BadRequestException`/`ForbiddenException`/etc. with a clear message and it reaches the
  frontend (the React side reads `err.response.data.message`). Without this, prod (debug off)
  returned HTML and the UI only showed a generic failure.
- Routes: API is a `prefix('Api', ['path' => '/api'])` scope in `config/routes.php`.
- **CSRF is disabled for `/api`** (stateless token API) via `Application::csrfMiddleware()`'s
  skip callback. CORS is handled by `App\Middleware\CorsMiddleware` (reflects all origins in
  debug; otherwise uses `App.corsOrigins`).
- `FactoryLocator` fallback table classes are **disabled** (`Application::bootstrap`). Every
  table you reference (incl. via `belongsTo`/`hasMany`) needs a real `*Table` class in
  `src/Model/Table/` — there is no auto-generated fallback.

### Auth is foundation-level, not production-grade
Login issues an opaque token stored on `users.api_token` (30-day expiry); passwords use PHP
`password_hash`/`password_verify` (see `User::_setPassword`/`verifyPassword`). This avoids
extra dependencies for the skeleton. **For production, migrate to the `cakephp/authentication`
plugin** (JWT or session) and move hashing to its `DefaultPasswordHasher`.

### Frontend structure
- `src/api/client.js` — single axios instance; injects the bearer token from localStorage.
  Base URL is `VITE_API_BASE_URL` or `/api` (dev proxy).
- `src/context/AuthContext.jsx` — `useAuth()` provides `{ user, role, login, logout }`;
  resolves the current user from a stored token on boot via `/auth/me`.
- `src/components/ProtectedRoute.jsx` wraps authed routes; pass `roles={[...]}` to restrict.
- All five domain module pages in `src/pages/` are implemented (see below); no page is a
  placeholder.
- **Public routes** `/privacy` and `/terms` (`PrivacyPolicy.jsx`, `TermsOfService.jsx`) render
  outside `ProtectedRoute`/`Layout` (no auth, no nav) — they're linkable from the login page.
- **Loading states use Tailwind skeletons, not spinners**, for initial data loads:
  `src/components/Skeleton.jsx` exports `Skeleton`, `SkeletonTable`, `SkeletonTableRows`,
  `SkeletonCards`. Reuse these instead of a `<Spinner/>` for page/table/card loads (inline
  action buttons keep their small spinner).

### Configuration
- Production config is **env-driven in `config/app.php`** (committed): `Datasources.default` reads
  `DATABASE_URL` (Railway DSN, wins) or `DB_HOST/PORT/USERNAME/PASSWORD/DATABASE`; `Security.salt`
  ← `SECURITY_SALT`; `App.corsOrigins` ← `CORS_ORIGINS` (comma-separated). So the app needs **no
  `app_local.php` in production**.
- `config/app_local.php` is git-ignored, overrides app.php locally with XAMPP defaults, and is
  excluded from the Docker image (`.dockerignore`).
- `backend/config/.env.example` and `frontend/.env.example` document the vars.

### Deployment (see `DEPLOYMENT.md`)
- Frontend → **Cloudflare Pages** (root `frontend`, build `npm run build`, output `dist`;
  `frontend/public/_redirects` provides the SPA fallback; `VITE_API_BASE_URL` set at build time).
- Backend → **Railway** via `backend/Dockerfile` (Apache, docroot = `webroot`). `docker/entrypoint.sh`
  binds Railway's `$PORT` and runs `migrations migrate` on start. DB = **Railway MySQL**.
- Required prod env on the API: `DATABASE_URL`, `SECURITY_SALT`, `DEBUG=false`, `APP_FULL_BASE_URL`
  (HostHeaderMiddleware blocks requests without it), `CORS_ORIGINS`.
- `CorsMiddleware` answers preflight `OPTIONS` directly with 204 + headers (it must NOT route to a
  controller, which would 405). `*.sh`/`Dockerfile` are pinned to LF in `.gitattributes` (CRLF
  breaks the Linux entrypoint).

## API endpoints (implemented)
- `POST /api/auth/login` · `GET /api/auth/me` · `POST /api/auth/logout`
- `GET|POST /api/properties` (owner-only create; owner index contains each property's admin) · `PATCH|PUT /api/properties/{id}` (owner-only edit, incl. subscription_status & subscription_fee)
- `GET /api/reports/owner-dashboard` (owner-only) — subscription revenue (week/month/YTD from each subscriber's monthly fee) + counts (hotels, active subscriptions, admins)
- `GET /api/reports/admin-dashboard` (admin-only, own property) — cards (inventory items, occupied rooms, guests today, open food orders) + collected revenue (week/month/YTD/all-time)
- `GET /api/reports/daily-collection[?date=YYYY-MM-DD | ?month=&year= | ?from=&to=]` — money collected in the window (settled invoices by `settled_at` + paid food orders); defaults to today; **the month+year and from/to (custom date range) forms are owner/admin-only** — a receptionist may only view one day (their entire Dashboard is this report)
- `GET /api/reports/monthly-visits[?year=YYYY]` (**admin-only**, own property) — seasonality: count of non-cancelled reservations per calendar month of the given year (defaults to current year), bucketed by the reservation's `check_in` date, one `COUNT` query per month rather than `GROUP BY MONTH(...)` (same `ONLY_FULL_GROUP_BY`-avoidance idiom as `admin-dashboard`'s revenue buckets) — powers the Dashboard's "Seasonality" line chart
- `GET|POST /api/users` · `PATCH|PUT /api/users/{id}` (rename / activate — **can't deactivate your own account**) · `POST /api/users/{id}/reset-password` — staff management (owner & admin only; admins may change their own password & reset their receptionists, but not a peer admin's; see `UsersController`)
- `GET|POST /api/inventory-categories` (create **owner/admin only**; a name already used by the property is rejected — case-insensitive check in `InventoryCategoriesController::add()`) · `DELETE /api/inventory-categories/{id}` (**owner/admin only**; refused while items still use it)
- `GET /api/inventory-items` · `POST /api/inventory-items` (**owner/admin only**) · `GET /api/inventory-items/{id}` · `PUT|PATCH /api/inventory-items/{id}` (**owner/admin only**; edit fixes category/`tracking_type` etc., never touches quantity) · `DELETE /api/inventory-items/{id}` (**owner/admin only**; **soft-delete** — sets `deleted_at`, hides it from inventory/menu linking, keeps `stock_movements` so the ledger stays intact; unlinks menu items; its own sub-items, if any, become top-level again). Consumables can carry a `parent_id` to itemize a sub-item under a parent (one level deep, consumables only — enforced server-side by `InventoryItemsController::assertValidParent()`)
- `GET /api/stock-movements[?inventory_item_id=]` · `POST /api/stock-movements` (manual move is **owner/admin only**; accepts an optional `note` — the Inventory stock-in modal uses it to record what exactly was restocked; receptionists' stock-out happens via Food & Orders, which records the movement internally stamped to them)
- `GET /api/receipt-series` (any authed) · `POST /api/receipt-series` (**owner/admin only**; registers a pre-printed booklet — `type` is `invoice` (Physical/Sales Invoice) or `official_receipt`, plus optional `prefix`, `start_number`/`end_number`; zero-padding width is taken from how `start_number` was typed, e.g. `"0001"` → 4 digits) · `PATCH|PUT /api/receipt-series/{id}` (**owner/admin only**; toggles `is_active`) · `DELETE /api/receipt-series/{id}` (**owner/admin only**; refused once any number has been issued — deactivate instead)
- `GET|POST /api/rooms` (create **owner/admin only**) · `PATCH|PUT /api/rooms/{id}` · `DELETE /api/rooms/{id}` (**owner/admin only**; refused if the room has reservations, removes room-specific rates)
- `GET /api/room-rates[?room_id=]` · `POST /api/room-rates` (**owner/admin only**) · `PATCH|PUT /api/room-rates/{id}` (**owner/admin only**; fix a mistyped name/rate/target room — receptionists read rates but can't change them; a rate carries an optional `description` of the amenities & bed type the guest gets)
- `GET /api/booking-sources` (any authed; **read-only** — starts empty for every property; a row is created only as a side effect of `POST /api/promo-rates`/`PATCH /api/promo-rates/{id}`, never through this endpoint)
- `GET /api/promo-rates[?source=]` (any authed — the booking form reads them) · `POST /api/promo-rates` · `PATCH|PUT /api/promo-rates/{id}` (writes **owner/admin only**; a row = booking `source` + rate `multiplier` (> 0, of the room's original rate), optionally room-specific via `room_id`; both take a typed `source_name`, never a `source` code — `BookingSourcesTable::resolveOrCreate()` resolves it to an existing source by name (case-insensitive) or creates a new one, so adding/editing a promo rate is the only way a booking source comes into being) · `DELETE /api/promo-rates/{id}`
- `GET /api/extra-charges` (any authed; index **auto-seeds** the built-in `early_check_in` row per property) · `POST /api/extra-charges` (**owner/admin only**; custom charge, `code` null) · `PATCH|PUT /api/extra-charges/{id}` (**owner/admin only**; set amount/active, built-in row's name & code are fixed) · `DELETE /api/extra-charges/{id}` (**owner/admin only**; refuses the built-in early check-in row)
- `GET|POST /api/reservations[?status=]` · `POST /api/reservations/{id}/{check-in|check-out|cancel}` (transitions stamp `checked_in_at`/`checked_out_at`/`cancelled_at`; an **advance booking** (check-in after today, guest on file) collects a **50% downpayment** of the quoted total as an immediately-settled invoice; **check-out** credits it against the room charge; **cancel** from `booked` refunds 90% and retains 10% (`DOWNPAYMENT_RATE`/`CANCELLATION_RETENTION`); **check-in** accepts `early_check_in:true` → posts the configured early check-in fee to the guest's invoice; **cancel** reverses any early check-in fee; booking with a `guest_id` **completes** that guest's empty detail fields without overwriting; `promo_rate` is **never client-supplied** — booking resolves it server-side from `promo_rates` for OTA sources) · `POST /api/reservations/{id}/payment` (any authed; `{payment_status: unpaid|paid}` — a Front Desk operational flag independent of the booking lifecycle and of invoice settlement, toggled from the reservations table)
- `GET /api/guests[?guest_type=&q=&page=&limit=]` (**paginated** → `{guests,total,page,limit}`; `limit` is only clamped 5–100 when a caller passes it — omitting it, as the Food & Orders charge-to-room picker and the Front Desk booking combobox do, returns the same wide unpaginated window (500) the endpoint always returned, so those client-side search comboboxes aren't affected by the Guests tab's pagination) · `GET /api/guests/stats` (total/local/foreign count **today's registrations only** — the cards reset daily; in_house is current) · `GET /api/guests/match?full_name=&email=&contact_number=` (de-dup candidates) · `GET|PATCH /api/guests/{id}` · `POST /api/guests` (409 + `duplicates` on a look-alike unless `force`)
- `GET|POST /api/food-menu-items[?available=1][?type=food|linen]` · `PATCH|PUT /api/food-menu-items/{id}` · `DELETE /api/food-menu-items/{id}` (owner/admin; **soft-delete** — sets `deleted_at`, hides it from the menu/new orders, keeps the row so order history stays intact). `type` (`food`|`linen`, default `food`) picks which Food & Orders management tab the item lives on; if the linked `inventory_item_id` is out of stock (`quantity <= 0`), the item is **force-saved unavailable** regardless of what was requested (`FoodMenuItemsController::resolveAvailability()`)
- `GET|POST /api/food-orders[?status=&date=YYYY-MM-DD|all&page=&limit=]` (index is **paginated** → returns `{orders,total,page,limit}`; `date` defaults client-side to today for a fresh-start view, `limit` clamped 5–100; `items[]` entries are either a menu line (`food_menu_item_id`, `quantity`) or a **custom line** (`description`, `price`, `quantity` — no menu item, no stock deduction, e.g. cooking of guest-brought food); optional `discount_type` (`senior`|`pwd`) applies a statutory **20% off the items subtotal** and requires `discount_name` + `discount_id_number` (the beneficiary's ID, kept on the order and shown on the invoice line); optional `cooking_charge` is added **after** the discount, since it's a service fee, not food; `payment_method` (`cash`|`gcash`|`maya`|`gotyme`) is **required when `payment_status` is `paid`**, null otherwise) · `GET /api/food-orders/{id}` · `POST /api/food-orders/{id}/{serve|cancel}` (a **receptionist may not cancel a `served` + `paid` order** — owner/admin only)
- `GET /api/invoices[?guest_id=&status=&date=YYYY-MM-DD|all]` (`date` hides other days, but **open tabs always show**; index includes `InvoiceLines` for the list's charges summary) · `GET /api/invoices/{id}` (with line items, for the folio-style detail modal) · `POST /api/invoices/{id}/settle` (optional `{use_invoice, use_or}` booleans each consume the next number from the property's active `receipt-series` of that type and stamp it onto `invoices.invoice_number`/`or_number`; throws if no active series has numbers left)

Implemented frontend pages (all five modules): `src/pages/Inventory.jsx` (Consumables / Reusables /
**Receipt Booklets**), `src/pages/Staff.jsx`,
`src/pages/FrontDesk.jsx` (Reservations / Rooms / Rates / **Promo Rates** / Calendar / **Extra Charges** [admin-only]), `src/pages/Guests.jsx`
(count cards + registry + stay history), and `src/pages/Food.jsx` (Orders / **Food** / **Linens** / Invoices).
`src/pages/Subscribers.jsx` (owner-only) manages subscribing hotels/resorts + their admins + fee.

### Navigation & dashboards are role-scoped
Nav visibility is driven by `roles` per item in `Layout.jsx`; **enforce the same on the backend
and via `ProtectedRoute roles=` in `App.jsx`** (frontend guards are UX only):
- **owner** (platform operator) → Dashboard + Subscribers only. Dashboard = subscription revenue
  (week/month/YTD) + active-user counts (`/reports/owner-dashboard`).
- **admin** (hotel/resort head) → Dashboard, Inventory, Front Desk, Guests, Food & Orders, Staff.
  Dashboard = ops cards + collected revenue (`/reports/admin-dashboard`). Admin adds Receptionists.
- **receptionist** → Dashboard, Inventory, Front Desk, Guests, Food & Orders (no Staff/
  Subscribers). Their Dashboard is **only the daily collection report** (single-day date
  filter; the backend rejects month/year queries from receptionists). The admin Dashboard
  additionally has a Collections section filterable by day or by month+year.

### Revenue model
- **Owner (subscription) revenue**: `properties.subscription_fee` is each subscriber's monthly
  fee; owner revenue is that fee projected (week = MRR·12/52, month = MRR, YTD = MRR·months-elapsed).
- **Admin (hotel) revenue = collected**: Σ settled `invoices.total` (bucketed by `invoices.settled_at`,
  stamped in `InvoicesController::settle`) + Σ `paid` `food_orders.total` (by `created`). Charge-to-room
  food already lives inside invoices, so only `paid` food orders are added (no double count).
- **Room revenue is persisted on Mark paid, falling back to check-out**: `ReservationsController::postRoomCharge()`
  posts the `quote()` subtotal (itemized with any senior/PWD discount as its own negative line) as
  `reservation` line(s) on the guest's invoice (via `InvoicesTable::addLine`), so rooms become
  collectable revenue. It's called from both `payment()` (the moment a reservation is marked
  `paid`, whether that's at check-in or any time before check-out) and `transition()`'s check-out
  step; it's idempotent (a no-op if a `reservation`-sourced line already exists for that booking
  via `InvoicesTable::invoiceForLine()`), so whichever happens first is the one that posts it, and
  check-out is just a fallback for a reservation that skipped Mark paid. The same call also posts
  the downpayment credit, if any — see below. Cancelling reverses the room charge
  (`removeLinesFor('reservation', ...)`) alongside the early check-in fee reversal.
- **Downpayments are collected at booking**: an advance booking creates an immediately-settled
  invoice (`InvoicesTable::settledInvoiceWith`, `settled_at` = now) holding the 50% `downpayment`
  line, so it counts as collected the day it's taken. `postRoomCharge()` posts the offsetting
  negative `downpayment_credit` line **in the same call** that posts the room charge (Mark paid or
  check-out, whichever fires first), and **only once that charge line actually exists** on the
  invoice (from this call or an earlier one) — never on its own, so a room rate that can't be
  resolved right now (e.g. an edited/removed rate makes the quote 0) can't leave a stranded credit
  with nothing to offset; it posts once a later call succeeds in posting the charge. This also
  takes a `FOR UPDATE` lock on the reservation row first (mirroring `ReceiptSeriesTable::assignNext()`),
  so two near-simultaneous calls (a Mark-paid double-click, a retried request) can't both pass the
  idempotency checks and double-post. Cancelling reverses both the room charge and the credit
  (`InvoicesTable::removeLinesFor()`, which recomputes the invoice's total from its remaining lines
  rather than subtracting incrementally, so a multi-line reversal is correct regardless of removal
  order) and appends a negative `downpayment_refund` (90%) to the settled downpayment invoice,
  leaving the retained 10% in
  revenue. The invoice folio groups all `downpayment*` lines under a "Downpayment" section (`Food.jsx`).

### Subscription model
StayVanta is subscription-based: the `owner` role is the **platform operator** (no property),
each `properties` row is a subscribing **hotel/resort**, and `admin`/`receptionist` are that
hotel's staff. `properties.subscription_status` (`active`|`inactive`) + `subscription_expires_at`
(nullable date) + `subscription_fee` (monthly) hold the subscription; the
`Property::subscription_active` virtual is the source of truth (status `active` AND not past
expiry). The owner manages subscribers (and flips a subscription) via `PATCH /api/properties/{id}`
(`PropertiesController::edit`) from the Subscribers page.

### Food (orders, menu, invoices)
`FoodOrdersTable::place()` is the orchestrator: in one transaction it saves the order + lines,
**decrements each linked Food Stock via `StockMovementsTable::record()`** (so the deduction is
stamped to the acting receptionist and logged in the ledger; short stock throws → whole order
rolls back), and for `charge_to_room` appends a line to the guest's open invoice
(`InvoicesTable::openInvoiceFor()` + `addLine()`). `cancelOrder()` reverses both (restock `in`
movements + `removeLinesFor()`). Menu management is owner/admin-only; a menu item links to an
`inventory_item_id` (optional — unlinked items, e.g. prepared dishes, don't touch stock).

Two `food_order_items` shapes: a **menu line** (`food_menu_item_id` set — price from the menu,
decrements the linked stock) or a **custom line** (`food_menu_item_id` null, `description` +
typed price/qty — no stock movement). Custom lines exist for a guest who brings their own food to
be cooked: the receptionist adds a custom item (e.g. "Cooking of guest's fish") for the cooking
labor itself, separate from `cooking_charge` below. `place()` computes
`total = subtotal − discount + cooking_charge` where `subtotal` sums every line (menu + custom):
- **`discount_type`** (`none`|`senior`|`pwd`, `FoodOrdersTable::STATUTORY_DISCOUNT` = 20%) applies
  to the items subtotal only; `discount_name`/`discount_id_number` (the beneficiary's name + ID)
  are required whenever a discount is set and are stamped on the order and echoed in the
  charge-to-room invoice line's description.
- **`cooking_charge`** is a flat fee added *after* the discount (it's a service charge, not
  discountable food) — for guests whose own brought-in food needs cooking; posted as its own
  invoice line (`Cooking charge — food order #N`) when charge-to-room.

A `paid` order also carries **`payment_method`** (`cash`|`gcash`|`maya`|`gotyme`), required by
`place()` when `payment_status` is `paid` and null otherwise — how the cash actually moved,
distinct from `payment_status` (the workflow state). The menu catalogue (`food_menu_items`) has a
**`type`** (`food`|`linen`, default `food`): `Food.jsx` shows it as two separate management tabs
— **Food** and **Linens** — each scoping its `MenuModal`'s Linked Stock picker to the matching
inventory category kind (`food_stock` / `linen`) so, e.g., a Linens or Utensils item never shows
up as linkable Food Stock. Both types remain orderable together in **New Order**, grouped by
category as before; clicking a menu row's name (not just its Add button) also adds one. Invoices
show a **VAT breakdown** (`Food.jsx`'s `vatBreakdown()`) assuming the standard 12% Philippine VAT
is already included in prices — `VATable Sales` + `VAT (12%)` are derived for display only
(`total / 1.12`), the stored total is unchanged.

Note: `Table` subclasses get other tables via `TableRegistry::getTableLocator()->get()`
(there is no `$this->getTableLocator()` on `Table` in CakePHP 5). Active-property selection for owners is handled by
`src/context/PropertyContext.jsx` (`useProperty()` → `propertyId`): staff are bound to their
own `property_id`; an owner's chosen property defaults to the first and persists in localStorage
(the navbar property selector was removed). Shared UI helpers:
`src/hooks/useSubmit.js` (form submit + CakePHP validation-error extraction) and
`src/utils/format.js` (`formatMoney`, PHP peso).

### Front Desk (reservations)
`ReservationsController` stamps the acting receptionist on `receptionist_id` at creation AND
on every lifecycle transition (check-in/out/cancel), so a booking always shows who last
handled it; transitions also flip `rooms.status` (occupied/available) and are guarded by an
allowed-from-state table. Pricing lives in `ReservationsTable::quote()`: nightly rate =
`promo_rate` (OTA) ?? resolved room rate; senior/PWD apply `STATUTORY_DISCOUNT` (20%).

**Booking sources are admin-configurable, not a hardcoded list — and have no standalone
management UI**: `booking_sources` holds each property's own OTA list (Cocotel, Agoda,
Booking.com, ...) instead of a fixed set baked into the code, but there's no dedicated "add a
source" screen; a row is created (or reused, if the typed name already matches one
case-insensitively) purely as a side effect of the Front Desk **Promo Rates** tab's "Add promo
rate" / "Edit promo rate" form, whose Source field is a free-text combobox
(`BookingSourcesTable::resolveOrCreate()`, called from `PromoRatesController`). `code` is
slugified from the typed name at creation and never changes afterward (it's what's stored on
`reservations.source`/`promo_rates.source`); `name` is the display label. `BookingSourcesController`
is read-only (`index` only) — it just serves the list to the New Reservation form's Source
dropdown and the promo rate form's autocomplete suggestions. `'walk_in'` is NOT a
`booking_sources` row — it's a fixed constant (`BookingSourcesTable::WALK_IN`) always offered
alongside the configured list, since it's not an OTA and never carries a promo rate.
**Promo rates are admin-configured multipliers, not typed by receptionists**: the
`promo_rates` table holds a `multiplier` (e.g. ×2 of the room's
original rate) per booking source, optionally per room; `PromoRatesTable::multiplierFor()`
resolves it (room-specific wins over property-wide) and `ReservationsController::add` stamps
base × multiplier onto `reservations.promo_rate` server-side, ignoring any client value (null
when no multiplier — or no base rate — exists). The booking form's Promo rate field is
disabled and shows the computed amount for display only. Booking
can create a guest inline (`guest_name`) inside the same transaction; the booking form's guest
field is a **search-as-you-type combobox** over existing guests — picking one reuses it
(`guest_id`) and pre-fills the detail fields, and any blank field the user then fills
**completes** that guest's record server-side (`ReservationsController::completeGuest`, fills
empties only, never overwrites). `resolveBaseRate()` prefers a room-specific `room_rates` row,
else the cheapest property-wide one.

**Early check-in** (the `extra_charges` model): a property has a built-in, non-deletable
`early_check_in` charge (auto-seeded by `ExtraChargesTable::earlyCheckInFor()`) plus any custom
charges, all managed admin-only on the Front Desk **Extra Charges** tab. When a receptionist
clicks Check in **before noon** (`isEarlyCheckInNow()`), the UI warns and, on confirm, posts
`early_check_in:true`; the backend bills the configured fee to the guest's invoice (and cancel
reverses it). Set the fee to 0 to disable.

The `FrontDesk.jsx` page also shows at-a-glance summary cards (available/occupied/maintenance
rooms + active reservations) and a **Calendar** tab: a date filter that derives, client-side
from the loaded rooms+reservations, which rooms are free on a date and which reservations
touch it (occupancy is the nights `[check_in, check_out)`, so the check-out day is free again).

**Reservation payment status**: `reservations.payment_status` (`unpaid`|`paid`, default `unpaid`)
is a Front Desk operational flag toggled via `POST /api/reservations/{id}/payment` — independent
of the booking lifecycle (`status`) and of the linked invoice's own `open`/`settled` state; the
reservations table shows a badge + Mark paid/unpaid button per row. Marking a reservation `paid`
opens (or reuses) the guest's invoice right away and posts the room charge onto it immediately via
`ReservationsController::postRoomCharge()` — so a guest who pays at check-in (or any time before
check-out) already shows the room amount on Food & Orders → Invoices, instead of only getting it
at check-out. `postRoomCharge()` itemizes it: the subtotal as one line (noting the OTA promo rate
in its description when `promo_rate` is set, via `ReservationsTable::SOURCE_LABELS`), then, if
`discount_type` is `senior`/`pwd`, a separate negative "Senior/PWD discount (20%)" line —
mirroring how `FoodOrdersTable::place()` itemizes its own discount, so the invoice folio always
shows the discount and promo rate as their own lines, not folded into a single net figure. It's
idempotent (checks `InvoicesTable::invoiceForLine('reservation', ...)` first), so **check-out**
calling the same helper is just a fallback for a reservation that was never marked paid — whichever
happens first is the one that actually posts the line. Cancelling a reservation reverses it
(alongside the early check-in fee) so a cancelled booking never leaves a room charge on the tab.
The reservations table's Total column shows the same breakdown (promo-rate note, discount amount,
downpayment) inline.

### Staff & roles enforcement
`UsersController` is the staff module and the canonical example of backend role enforcement:
owners create `admin`/`receptionist` for any property; admins create `receptionist` for their
own property only; `findManageable()` confines admins to their property and excludes owners.
Deactivating a user (`is_active = false`) blocks login; reset-password also nulls `api_token`
to revoke the active session. Receptionists created here are what make the accountability
stamps (`last_receptionist_id`, `stock_movements.receptionist_id`) reflect real people.

## Domain modules
- **Inventory** *(implemented)* — categories: Food Stocks (→ Drinks), Hygiene Kit, Linens,
  Utensils; `inventory_categories.parent_id` models sub-groups; `inventory_categories.kind`
  tags the type. Quantities change **only** via `StockMovementsTable::record()` (transactional:
  writes the ledger row, updates `quantity`, stamps `last_receptionist_id`; rejects negative stock).
  Use it as the template for the remaining modules. `inventory_items.tracking_type` is
  `consumable` (depletes on use — In/Out) or `reusable` (issued out then returned). For reusables
  `quantity` = available on the shelf and `total_quantity` = units owned, so *in use* =
  `total_quantity − quantity`; `record()`'s `$affectsTotal` flag moves the owned total too
  (acquire/retire) vs. only the available count (issue/return), and blocks returning more than owned.
  A **consumable** item can also carry `inventory_items.parent_id` to itemize a **sub-item**
  under it (one level deep — `Inventory.jsx`'s consumables table shows the parent with a
  ▶/▼ expand toggle revealing its sub-items, each tracked with its own stock/quantity). Inventory
  also has a **Receipt Booklets** tab (`Inventory.jsx`) managing `receipt_series` — see below.
- **Front Desk** (UI name for "Room Monitoring") — rooms, room rates, reservations, OTA
  sources (`reservations.source`: cocotel/agoda/trip_com/tripadvisor), senior/PWD discounts,
  additional beds.
- **Guests** *(implemented)* — registry + counts (`GuestsController::stats` → total / local / foreign /
  in_house, where total/local/foreign count only guests **registered today** (daily fresh-start
  cards) and in_house = distinct guests with a `checked_in` reservation). Guests are also
  created inline by the Front Desk booking flow.
- **Food & Orders** *(implemented)* — admin-managed menu (`food_menu_items`, split into **Food**
  and **Linens** by `type`, each optionally linked to a matching-category `inventory_item_id` so
  orders decrement stock); receptionist takes orders (`food_orders`). Payment is `paid` /
  `charge_to_room` / `unpaid`, plus a `payment_method` when `paid`; charge-to-room flows
  onto the guest's `invoices` (with a VAT breakdown shown for display). Cancel reverses stock +
  invoice. See `FoodOrdersTable::place()` (discount/cooking-charge/custom-item handling — see the
  Food section above).
- **Receipt series** *(implemented)* — `receipt_series` registers a property's pre-printed
  **Sales Invoice** and **Official Receipt** booklets (`ReceiptSeriesController`, owner/admin
  writes; managed on Inventory → **Receipt Booklets**): each row is a `type`
  (`invoice`|`official_receipt`) + numeric range (`start_number`..`end_number`) + `next_number`
  cursor, with `pad_length` captured from how the start was typed (`"0001"` → 4-digit
  zero-padding) so issued numbers read like the physical page. `ReceiptSeriesTable::assignNext()`
  (called from `InvoicesController::settle` when the caller sets `use_invoice`/`use_or`)
  atomically consumes the next number from the oldest active, non-exhausted series of that type
  (`FOR UPDATE` locked) and stamps the formatted number onto `invoices.invoice_number`/`or_number`;
  it throws if no active series has numbers left, which `settle()` surfaces as a 400. The Food &
  Orders **Settle** modal shows the invoice's itemized charges first, then lets the receptionist
  tick which document was issued. A series that has already issued a number can be deactivated
  but not deleted (the numbers are on real settled invoices).

The base schema is `backend/config/Migrations/20260615000000_InitialSchema.php`; each feature
since ships its own dated migration on top (subscriptions, revenue fields, soft-deletes,
extra charges, promo rates, downpayments, …) — add new schema changes the same way rather
than editing the base schema. `backend/config/Migrations/schema-dump-default.lock` is the
regenerated dump.
