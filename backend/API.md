# StayVanta API reference

Every endpoint lives under `/api` (`Api` prefix scope in `config/routes.php`), is served by a
controller in `src/Controller/Api/`, and requires `Authorization: Bearer <token>` unless noted.
Errors come back as JSON `{message, code, url}`. "Owner/admin only" is enforced server-side;
staff (admin/receptionist) are always scoped to their own property, owners pass `property_id`.

Domain rules behind these endpoints (pricing, discounts, downpayments, stock ledger) are
explained in `CLAUDE.md`; this file is the per-endpoint contract.

## Auth
- `POST /api/auth/login` (public) · `GET /api/auth/me` · `POST /api/auth/logout`

## Properties & reports
- `GET|POST /api/properties` — owner-only create; the owner's index contains each property's admin.
- `PATCH|PUT /api/properties/{id}` — owner-only edit, incl. `subscription_status` & `subscription_fee`.
- `GET /api/reports/owner-dashboard` (owner-only) — subscription revenue (week/month/YTD from
  each subscriber's monthly fee) + counts (hotels, active subscriptions, admins).
- `GET /api/reports/admin-dashboard` (admin-only, own property) — cards (inventory items,
  occupied rooms, guests today, open food orders) + collected revenue (week/month/YTD/all-time).
- `GET /api/reports/daily-collection[?date=YYYY-MM-DD | ?month=&year= | ?from=&to=]` — money
  collected in the window (settled invoices by `settled_at` + paid food orders); defaults to
  today. **The month+year and from/to forms are owner/admin-only** — a receptionist may only view
  one day (their entire Dashboard is this report).
- `GET /api/reports/monthly-summary[?year=YYYY]` (**admin-only**, own property) — seasonality per
  calendar month of the year (default current): count of non-cancelled reservations (bucketed by
  `check_in`) and collected revenue (same definition as `admin-dashboard`). One pair of queries
  per month rather than `GROUP BY MONTH(...)` (`ONLY_FULL_GROUP_BY` avoidance). Powers the
  Dashboard's "Seasonality" chart.

## Staff
- `GET|POST /api/users` · `PATCH|PUT /api/users/{id}` (rename / activate — **can't deactivate
  your own account**) · `POST /api/users/{id}/reset-password` (also nulls `api_token`).
  Owner & admin only; admins may change their own password & reset their receptionists, but not
  a peer admin's (see `UsersController::findManageable()`).

## Inventory
- `GET|POST /api/inventory-categories` — create **owner/admin only**; a name already used by the
  property is rejected (case-insensitive, `InventoryCategoriesController::add()`).
- `DELETE /api/inventory-categories/{id}` — **owner/admin only**; refused while items still use it.
- `GET /api/inventory-items[?tracking_type=consumable|reusable][?q=][?top_level=1][?page=&limit=]`
  → `{items,children,total,page,limit}`.
  - `limit` is only clamped 5–100 when passed; omitting it (the Food & Orders stock-linking
    pickers and the item modal's parent-item dropdown do) returns a wide unpaginated window (1000).
  - `top_level=1` (the Consumables table when not searching) pages over top-level items only;
    each returned parent's sub-items ride along in `children`, keyed by parent id.
  - `q` instead flattens to a direct name match across both levels (a matching sub-item's parent
    might not be on the same page), and `children` is empty.
  - Every item carries a computed `has_children` (correlated `EXISTS`, not `GROUP BY`).
- `POST /api/inventory-items` (**owner/admin only**) · `GET /api/inventory-items/{id}`.
- `PUT|PATCH /api/inventory-items/{id}` — **owner/admin only**; fixes category/`tracking_type`
  etc., never touches quantity. `parent_id` (sub-item) is one level deep, consumables only
  (`InventoryItemsController::assertValidParent()`).
- `DELETE /api/inventory-items/{id}` — **owner/admin only**; **soft-delete** (`deleted_at`):
  hidden from inventory/menu linking, `stock_movements` kept, menu items unlinked, its sub-items
  become top-level again.
- `GET /api/stock-movements[?inventory_item_id=]` · `POST /api/stock-movements` — manual move is
  **owner/admin only**; optional `note` (what was restocked). Receptionists' stock-out happens via
  Food & Orders, which records the movement internally stamped to them.
- `GET /api/receipt-series[?q=][?page=&limit=]` (any authed) — paginated, searchable by `prefix`;
  `limit` defaults to 100, otherwise clamped 5–100.
- `POST /api/receipt-series` — **owner/admin only**; `type` `invoice`|`official_receipt`, optional
  `prefix`, `start_number`/`end_number`; zero-padding width comes from how `start_number` was
  typed (`"0001"` → 4 digits).
- `PATCH|PUT /api/receipt-series/{id}` — **owner/admin only**; toggles `is_active`.
- `DELETE /api/receipt-series/{id}` — **owner/admin only**; refused once any number has been
  issued (deactivate instead).

## Front Desk
- `GET|POST /api/rooms` — create **owner/admin only**.
- `PATCH|PUT /api/rooms/{id}` — any authed staff may change `status`; changing
  `room_number`/`room_type` is **owner/admin only** (enforced by diffing incoming vs current values).
- `DELETE /api/rooms/{id}` — **owner/admin only**; refused if the room has reservations; removes
  room-specific rates.
- `GET /api/room-rates[?room_id=]` · `POST /api/room-rates` · `PATCH|PUT /api/room-rates/{id}` —
  writes **owner/admin only**. A rate has no name: it's identified by its room (or "all rooms") and
  carries an optional `description` (amenities & bed type).
- `GET /api/booking-sources` (any authed; **read-only**) — starts empty per property; rows are
  created only as a side effect of promo-rate writes.
- `GET /api/promo-rates[?source=]` (any authed) · `POST /api/promo-rates` ·
  `PATCH|PUT /api/promo-rates/{id}` · `DELETE /api/promo-rates/{id}` — writes **owner/admin only**.
  A row = booking source + `multiplier` (> 0, of the room's original rate), optionally
  room-specific via `room_id`. Writes take a typed `source_name`, never a `source` code —
  `BookingSourcesTable::resolveOrCreate()` matches by name (case-insensitive) or creates one.
- `GET /api/extra-charges` (any authed; **auto-seeds** the built-in `early_check_in` row) ·
  `POST /api/extra-charges` (**owner/admin only**; custom charge, `code` null) ·
  `PATCH|PUT /api/extra-charges/{id}` (**owner/admin only**; amount/active — the built-in row's
  name & code are fixed) · `DELETE /api/extra-charges/{id}` (**owner/admin only**; refuses the
  built-in row).
- `GET|POST /api/reservations[?status=]`
  - `walk_in` source → saved straight to `checked_in`, `check_in` forced to today, room flipped
    to `occupied`.
  - Any other source requires `booking_reference`; may carry `sold_rate` and a channel discount
    (`channel_discount_type` percent|fixed + `channel_discount_value`).
  - `total_guests` (default 1) + `discount_beneficiaries[]` (`{discount_type: senior|pwd, name,
    id_number}`); beneficiaries can't outnumber `total_guests`.
  - `referral` = `discount_amount` (optional, must be `> 0` when set).
  - `guest_id` reuses a guest and fills that guest's empty fields (never overwrites); `guest_name`
    creates one inline in the same transaction.
  - `promo_rate` is **never client-supplied** — resolved server-side from `promo_rates`.
  - Rejected if it would double-book the room for overlapping nights.
  - An advance booking (check-in after today, guest on file) collects a 50% downpayment as an
    immediately-settled invoice.
- `PATCH|PUT /api/reservations/{id}` — any authed staff. Room/dates/source/discount only.
  **400** once `status` isn't `booked`, and **400** once a downpayment has been collected (cancel
  and rebook). `promo_rate` recomputed as in `add()`; downpayment collected if the edit makes it an
  advance booking. Sending `discount_beneficiaries` replaces the set; omitting it leaves them.
  Omitting `channel_discount_type` keeps it; sending it blank clears it.
- `POST /api/reservations/{id}/{check-in|check-out|cancel}` — stamp
  `checked_in_at`/`checked_out_at`/`cancelled_at` and `receptionist_id`; flip room status.
  - check-in accepts `early_check_in:true` → posts the configured fee to the guest's invoice.
  - check-out posts the room charge (+ downpayment credit) if Mark paid hasn't already.
  - cancel from `booked` refunds 90% of the downpayment, retains 10%
    (`DOWNPAYMENT_RATE`/`CANCELLATION_RETENTION`), and reverses room charge, credit and early
    check-in fee.
- `POST /api/reservations/{id}/payment` (any authed) — `{payment_status: unpaid|paid}`; marking
  `paid` posts the room charge onto the guest's invoice immediately.

## Guests
- `GET /api/guests[?guest_type=&q=&page=&limit=]` → `{guests,total,page,limit}`. `limit` is only
  clamped 5–100 when passed; omitting it (charge-to-room picker, booking combobox) returns a wide
  unpaginated window (500).
- `GET /api/guests/stats` — total/local/foreign count **today's registrations only**; `in_house`
  is current (distinct guests with a `checked_in` reservation).
- `GET /api/guests/match?full_name=&email=&contact_number=` — de-dup candidates.
- `GET|PATCH /api/guests/{id}` · `POST /api/guests` (409 + `duplicates` on a look-alike unless `force`).

## Food & Orders
- `GET|POST /api/food-menu-items[?available=1][?type=food|linen]` ·
  `PATCH|PUT /api/food-menu-items/{id}` · `DELETE /api/food-menu-items/{id}` — owner/admin writes;
  delete is a **soft-delete** (`deleted_at`) so order history stays intact.
  - `type` (`food`|`linen`, default `food`) picks the management tab.
  - Out-of-stock linked `inventory_item_id` (or any short recipe ingredient) → **force-saved
    unavailable** (`FoodMenuItemsController::resolveAvailability()`).
  - `ingredients[]` (`{inventory_item_id, quantity}` per serving) — replaced wholesale
    (`saveIngredients()`).
  - `option_groups[]` (`{name, kind: choice|addon, options: [{label, price_delta,
    inventory_item_id}]}`) — replaced wholesale (`saveOptionGroups()`).
- `GET|POST /api/food-orders[?status=&date=YYYY-MM-DD|all&page=&limit=]` → index returns
  `{orders,total,page,limit}`; `date` defaults client-side to today, `limit` clamped 5–100.
  - `items[]`: a menu line (`food_menu_item_id`, `quantity`, optional
    `selected_options[]` = `{option_id, quantity}`) or a custom line (`description`, `price`,
    `quantity` — no stock movement).
  - `total_diners` (default 1) + `discount_beneficiaries[]` (`{discount_type: senior|pwd, name,
    id_number}`); beneficiaries can't outnumber diners.
  - `cooking_charge` (optional, added after the discount).
  - `payment_status` `paid`|`charge_to_room`|`unpaid`; `payment_method`
    (`cash`|`gcash`|`maya`|`gotyme`) **required when `paid`**, null otherwise.
- `GET /api/food-orders/{id}` · `POST /api/food-orders/{id}/{serve|cancel}` — a **receptionist may
  not cancel a `served` + `paid` order** (owner/admin only). Cancel restocks and removes invoice lines.

## Invoices
- `GET /api/invoices[?guest_id=&status=&date=YYYY-MM-DD|all]` — `date` hides other days, but
  **open tabs always show**; includes `InvoiceLines`.
- `GET /api/invoices/{id}` — with line items (folio detail modal).
- `POST /api/invoices/{id}/settle` — stamps `settled_at`; optional `{use_invoice, use_or}` each
  consume the next number from the property's active receipt series of that type
  (`ReceiptSeriesTable::assignNext()`) onto `invoice_number`/`or_number`; 400 if no active series
  has numbers left.
