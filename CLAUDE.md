# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

StayVanta — "All-in-One Hotel & Resort Management Platform". A monorepo of two
independently-deployed apps that talk over a JSON API:

- `frontend/` — React 19 SPA (Vite). UI kit is **React-Bootstrap**; **Tailwind v4**
  is layered on top for utility tweaks. Routing via react-router-dom v7, HTTP via axios.
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
- Tests: `vendor/bin/phpunit` — single file: `vendor/bin/phpunit tests/TestCase/Path/ThingTest.php`
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
- `GET|POST /api/users` · `PATCH|PUT /api/users/{id}` (rename / activate — **can't deactivate your own account**) · `POST /api/users/{id}/reset-password` — staff management (owner & admin only; admins may change their own password & reset their receptionists, but not a peer admin's; see `UsersController`)
- `GET|POST /api/inventory-categories` (create **owner/admin only**) · `DELETE /api/inventory-categories/{id}` (**owner/admin only**; refused while items still use it)
- `GET /api/inventory-items` · `POST /api/inventory-items` (**owner/admin only**) · `GET /api/inventory-items/{id}` · `PUT|PATCH /api/inventory-items/{id}` (**owner/admin only**; edit fixes category/`tracking_type` etc., never touches quantity) · `DELETE /api/inventory-items/{id}` (**owner/admin only**; **soft-delete** — sets `deleted_at`, hides it from inventory/menu linking, keeps `stock_movements` so the ledger stays intact; unlinks menu items)
- `GET /api/stock-movements[?inventory_item_id=]` · `POST /api/stock-movements` (manual move is **owner/admin only**; accepts an optional `note` — the Inventory stock-in modal uses it to record what exactly was restocked; receptionists' stock-out happens via Food & Orders, which records the movement internally stamped to them)
- `GET|POST /api/rooms` (create **owner/admin only**) · `PATCH|PUT /api/rooms/{id}` · `DELETE /api/rooms/{id}` (**owner/admin only**; refused if the room has reservations, removes room-specific rates)
- `GET /api/room-rates[?room_id=]` · `POST /api/room-rates` (**owner/admin only**) · `PATCH|PUT /api/room-rates/{id}` (**owner/admin only**; fix a mistyped name/rate/target room — receptionists read rates but can't change them)
- `GET /api/extra-charges` (any authed; index **auto-seeds** the built-in `early_check_in` row per property) · `POST /api/extra-charges` (**owner/admin only**; custom charge, `code` null) · `PATCH|PUT /api/extra-charges/{id}` (**owner/admin only**; set amount/active, built-in row's name & code are fixed) · `DELETE /api/extra-charges/{id}` (**owner/admin only**; refuses the built-in early check-in row)
- `GET|POST /api/reservations[?status=]` · `POST /api/reservations/{id}/{check-in|check-out|cancel}` (transitions stamp `checked_in_at`/`checked_out_at`/`cancelled_at`; **check-in** accepts `early_check_in:true` → posts the configured early check-in fee to the guest's invoice; **cancel** reverses any early check-in fee; booking with a `guest_id` **completes** that guest's empty detail fields without overwriting)
- `GET /api/guests[?guest_type=&q=]` · `GET /api/guests/stats` · `GET /api/guests/match?full_name=&email=&contact_number=` (de-dup candidates) · `GET|PATCH /api/guests/{id}` · `POST /api/guests` (409 + `duplicates` on a look-alike unless `force`)
- `GET|POST /api/food-menu-items` · `PATCH|PUT /api/food-menu-items/{id}` · `DELETE /api/food-menu-items/{id}` (owner/admin; **soft-delete** — sets `deleted_at`, hides it from the menu/new orders, keeps the row so order history stays intact)
- `GET|POST /api/food-orders[?status=&date=YYYY-MM-DD|all&page=&limit=]` (index is **paginated** → returns `{orders,total,page,limit}`; `date` defaults client-side to today for a fresh-start view, `limit` clamped 5–100) · `GET /api/food-orders/{id}` · `POST /api/food-orders/{id}/{serve|cancel}` (a **receptionist may not cancel a `served` + `paid` order** — owner/admin only)
- `GET /api/invoices[?guest_id=&status=&date=YYYY-MM-DD|all]` (`date` hides other days, but **open tabs always show**; index includes `InvoiceLines` for the list's charges summary) · `GET /api/invoices/{id}` (with line items, for the folio-style detail modal) · `POST /api/invoices/{id}/settle`

Implemented frontend pages (all five modules): `src/pages/Inventory.jsx`, `src/pages/Staff.jsx`,
`src/pages/FrontDesk.jsx` (Reservations / Rooms / Rates / Calendar / **Extra Charges** [admin-only]), `src/pages/Guests.jsx`
(count cards + registry + stay history), and `src/pages/Food.jsx` (Orders / Menu / Invoices).
`src/pages/Subscribers.jsx` (owner-only) manages subscribing hotels/resorts + their admins + fee.

### Navigation & dashboards are role-scoped
Nav visibility is driven by `roles` per item in `Layout.jsx`; **enforce the same on the backend
and via `ProtectedRoute roles=` in `App.jsx`** (frontend guards are UX only):
- **owner** (platform operator) → Dashboard + Subscribers only. Dashboard = subscription revenue
  (week/month/YTD) + active-user counts (`/reports/owner-dashboard`).
- **admin** (hotel/resort head) → Dashboard, Inventory, Front Desk, Guests, Food & Orders, Staff.
  Dashboard = ops cards + collected revenue (`/reports/admin-dashboard`). Admin adds Receptionists.
- **receptionist** → Inventory, Front Desk, Guests, Food & Orders only (no Dashboard/Staff/
  Subscribers). `Dashboard.jsx` redirects receptionists to `/front-desk`.

### Revenue model
- **Owner (subscription) revenue**: `properties.subscription_fee` is each subscriber's monthly
  fee; owner revenue is that fee projected (week = MRR·12/52, month = MRR, YTD = MRR·months-elapsed).
- **Admin (hotel) revenue = collected**: Σ settled `invoices.total` (bucketed by `invoices.settled_at`,
  stamped in `InvoicesController::settle`) + Σ `paid` `food_orders.total` (by `created`). Charge-to-room
  food already lives inside invoices, so only `paid` food orders are added (no double count).
- **Room revenue is persisted on check-out**: `ReservationsController::transition` posts the
  `quote()` total as a `reservation` line on the guest's invoice (via `InvoicesTable::addLine`),
  so rooms become collectable revenue (previously `quote()` was read-time only).

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
`promo_rate` (OTA) ?? resolved room rate; senior/PWD apply `STATUTORY_DISCOUNT` (20%). Booking
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
- **Front Desk** (UI name for "Room Monitoring") — rooms, room rates, reservations, OTA
  sources (`reservations.source`: cocotel/agoda/trip_com/tripadvisor), senior/PWD discounts,
  additional beds.
- **Guests** *(implemented)* — registry + counts (`GuestsController::stats` → total / local / foreign /
  in_house, where in_house = distinct guests with a `checked_in` reservation). Guests are also
  created inline by the Front Desk booking flow.
- **Food & Orders** *(implemented)* — admin-managed menu (`food_menu_items`, each optionally
  linked to a Food Stock `inventory_item_id` so orders decrement stock); receptionist takes
  orders (`food_orders`). Payment is `paid` / `charge_to_room` / `unpaid`; charge-to-room flows
  onto the guest's `invoices`. Cancel reverses stock + invoice. See `FoodOrdersTable::place()`.

The base schema is `backend/config/Migrations/20260615000000_InitialSchema.php`; later
migrations add the subscription columns (`AddSubscriptionFieldsToProperties`) and revenue-tracking
columns (`AddRevenueFields` — `invoices.settled_at`, etc.). `schema-dump-default.lock` is the
regenerated dump.
