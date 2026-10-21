# Security findings

A permanent record of security issues found in StayVanta: what was wrong, how it was found, how it
was fixed, and whether it was ever exploited. Newest first. Add an entry for every finding, even
one fixed before release, so the pattern stays visible.

---

## SF-2026-005 · A menu item could be linked to another hotel's stock

| | |
| --- | --- |
| **Status** | Open: fix prepared, not deployed (2026-10-21) |
| **Severity** | Medium: cross-property read (another hotel's stock item name, quantity and category) and cross-property write (each sale moved the other hotel's stock); needs a crafted API request by a Manager |
| **Found** | 2026-10-21, by the SF-2026-001–004 regression verification (stage B.1, the inventory of ids that arrive in request bodies) |
| **Fixed** | Not yet |
| **Exploited** | No: the production read-only audit (below) found no cross-property link and no cross-property stock movement, ever |

**What was wrong.** `POST /api/food-menu-items` and `PATCH /api/food-menu-items/{id}` saved the
menu item's single stock link (`inventory_item_id`) from the request without checking that the
stock item belonged to the menu item's property. The recipe's ingredients and the options' stock
items were checked; this one field wasn't. With another hotel's item id:
- the menu response included that hotel's stock item (name, quantity, category);
- every sale of the menu item moved *that hotel's* stock (`FoodOrdersTable::place()` loaded the
  item by id alone, and `StockMovementsTable::record()` never compared the item's property with the
  request's), writing a `stock_movements` row and an Activity line at that hotel naming our staff;
- our orders were refused whenever that hotel's item ran short; cancelling returned stock to it.

The POS screen only offers the hotel's own stock, so it took a crafted request.

**Fix.**
- *Primary fix (menu item linked stock validation):* `FoodMenuItemsController::linkedStockItem()`
  refuses a link that isn't the property's own live stock (400 "The selected stock item was not
  found."), on create and edit. The menu response's stock link joins only the property's own item,
  so a link saved before the fix shows nothing.
- *Defence in depth (`StockMovementsTable::record()` property enforcement):* any movement of an item
  from a property other than the request's is refused, whatever led to it: sales, cancellations,
  recipes, options and manual movements.

Tests:
`PropertyIsolationApiTest::testAMenuItemCannotLinkAnotherPropertysStock`,
`testALinkToAnotherPropertysStockNeitherShowsNorMovesIt`.

**Historical analysis (production, read-only).** Run on 2026-10-21 13:14 UTC inside a MySQL
`READ ONLY` transaction (`transaction_read_only = 1`, enforced by the server; MySQL 9.7.2), rolled
back; printed ids and counts only.
- 2 properties; 96 menu items (88 with a stock link), 15 recipe rows, 5 options with stock,
  12 POS stock movements; 96 menu-item change-log rows since 2026-10-04 23:57 UTC.
- 0 menu items (deleted ones included) linked to another property's stock, and 0 linked to a missing
  item; 0 recipe rows and 0 options with another property's stock; 0 sale option snapshots with it.
- 0 POS stock movements at a property other than their order's; 0 stock movements at a property
  other than their item's; 0 POS movements by someone with no membership at that property.
- 0 menu-item change-log rows naming another property's stock, before or after.

**Conclusion:** never exercised. Stock movements are append-only and cover all history, so no sale
ever moved another hotel's stock. The change log starts on 2026-10-04, so a link set and removed
before then with no sale in between would leave no trace; nothing suggests one.

---

## SF-2026-004 · A Manager could switch off or rename a fellow Manager

| | |
| --- | --- |
| **Status** | Fixed in Permissions Phase 3a (`1ea4e25`, on staging 2026-10-12; production on the user's go) |
| **Severity** | Low to medium: a Manager could deactivate (sign out and lock out), reactivate or rename another Manager at the same property, against the documented rule that Managers manage only Front Desk Staff |
| **Found** | 2026-10-12, while moving the staff hierarchy from role codes to permissions (Phase 3a) |
| **Fixed** | Phase 3a: `App\Auth\StaffHierarchy` decides who manages whom for every account action (create, edit, reset password, access history): only roles whose permissions are a strict subset of yours |
| **Exploited** | No: production and staging access events show no account change by a Manager to another Manager |

**What was wrong.** `UsersController::edit()` confined a Manager to their own property but checked
the role of the account being changed only for a role change. Renaming, deactivating and
reactivating a peer Manager were allowed, and the Staff screen offered Deactivate on every row but
your own. Only a peer's password reset was refused, by a separate role-code check. A Manager could
also read a peer Manager's access history.

**Fix.** Every action on someone else's account requires `StaffHierarchy::manages()` (403
otherwise); the staff list returns `manageable` and the screens offer only those actions. Tests:
`StaffHierarchyApiTest::testAManagerManagesFrontDeskStaffButNeverAPeer`.

---

## SF-2026-003 · Guest names, emails and phone numbers reached infrastructure logs

| | |
| --- | --- |
| **Status** | Fixed; in production 2026-10-09 (`51ffd14`). G8 removed the GET forms (`GET /guests?q=` refused, `GET /guests/match` gone) |
| **Severity** | Low to medium: personal data in Railway's log storage, readable by anyone with access to the Railway project; not exposed to the public |
| **Found** | 2026-10-09, while preparing the G5 retention proposal (reading the Apache log format and the guest API) |
| **Fixed** | G5, decision P6: guest search and matching send their values in a POST body; the access log records method, path and protocol only, never the query string |
| **Exploited** | No: the logs are only reachable through the Railway project |

**What was wrong.** The web server's access log line recorded each request's full URL. The booking
form's and Guests' duplicate check (`GET /guests/match?full_name=…&email=…&contact_number=…`) and
guest search (`GET /guests?q=…`) put guest names, email addresses and phone numbers in the URL, so
they were written to the container's stdout and kept by Railway's log storage, outside the app's
retention routine.

**Fix.** `POST /guests/match` and `POST /guests/search` take the values in the body (the GET forms
stay one release for compatibility; the screens no longer use them). The Apache `LogFormat` logs
`%m %U %H` instead of `%r`, so no query string reaches the log. Lines already written expire under
Railway's own log retention. Test: `RetentionApiTest::testGuestSearchAndMatchingTravelInTheBody`.

---

## SF-2026-002 · Reactivating an account revived its old session

| | |
| --- | --- |
| **Status** | Fixed on staging (release 10a); production when 10a is promoted |
| **Severity** | Low: needs a deactivated account to be reactivated within 30 days, and a device that still holds its old token |
| **Found** | 2026-10-05, while preparing the build step 10 proposal (reading `UsersController::edit()`) |
| **Fixed** | Build step 10, part 1 (decision A8): deactivating revokes the token in the same transaction |
| **Exploited** | Unknown: sign-ins weren't recorded before step 10, so it can't be shown either way |

**What was wrong.** `PATCH /api/users/{id}` with `is_active: false` only flipped the flag. Every
request checks `is_active`, so the account was blocked at once, but its sign-in token (valid 30
days) stayed on the row. Reactivating the account within those 30 days made the old token valid
again: any device still holding it, such as a shared front-desk computer, was signed back in
without a password.

**Fix.** Deactivation now ends the session: the token and its expiry are cleared under a lock on the
user row, in the same transaction as the `account_deactivated` event, and a `session_ended` event
records it. A password set by someone else does the same (it already cleared the token). Test:
`AccessEventsApiTest::testDeactivatingNeedsAReasonEndsTheSessionAndReactivatingRevivesNothing`.

---

## SF-2026-001 · POS orders could reference another hotel's guest, room or reservation

| | |
| --- | --- |
| **Status** | Resolved |
| **Severity** | Medium: cross-tenant read of guest personal data (name, nationality, address, contact number, email) by guessing ids |
| **Found** | 2026-10-02, by the new `PropertyIsolationApiTest` on its first CI run (build step 2) |
| **Fixed** | 2026-10-02, commit `f44c396`; deployed to production 2026-10-02 (`055bf8d`) |
| **Exploited** | No (read-only production audit below) |

**What was wrong.** `POST /api/food-orders` passed `guest_id`, `room_id` and `reservation_id` from
the request straight into the order (`FoodOrdersTable::place()`), without checking that they
belonged to the ordering property. A Manager or Front Desk Staff at one hotel could place an order
naming another hotel's guest, room or reservation. The order was saved, and the response (which
includes the order's guest and room) returned the other hotel's records. The order also stayed in
the first hotel's order list with the other hotel's guest attached.

Charge-to-room orders were not affected: they already required the guest to be checked in at the
ordering property. The POS screen itself only ever offered the hotel's own guests and never sent a
room or reservation id, so a normal user could not trigger it by accident; it needed a crafted API
request.

**Fix.** `FoodOrdersTable::assertOwnReferences()` checks that every referenced guest, room and
reservation belongs to the ordering property; otherwise the order is refused with
`400 "No such guest at this property."` (or room / reservation). It sits in the one function every
order goes through, so no caller can skip it.

**Verification.**
- `PropertyIsolationApiTest::testAnOrderCannotReferenceAnotherPropertysGuestRoomOrStay` fails on
  the old code and passes on the new; it stays in CI as a regression test.
- Confirmed on the live staging API: the same request returns 400.

**Historical analysis (production, read-only).** Run on 2026-10-02 inside a MySQL
`READ ONLY` transaction (`transaction_read_only = 1`, enforced by the server); printed ids and
counts only, no guest details.
- 11 POS orders in total (21 June to 22 July 2026), across 2 properties.
- 0 orders referencing another property's guest, room or reservation; 0 referencing a missing
  record. No order has ever carried a `room_id` or `reservation_id`.
- 0 invoices attached to another property's guest.
- POS orders can't be deleted through the API, so a successful exploit would have left a row.

**Conclusion:** never exercised, no guest data exposed, no remediation needed.

**Lesson.** Any id that arrives in a request body and points at another record needs the same
property check as an id in the URL. `PropertyIsolationApiTest` now covers this for every write
endpoint that accepts such ids; new endpoints must be added to it (see `CLAUDE.md`, testing).
