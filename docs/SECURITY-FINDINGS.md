# Security findings

A permanent record of security issues found in StayVanta: what was wrong, how it was found, how it
was fixed, and whether it was ever exploited. Newest first. Add an entry for every finding, even
one fixed before release, so the pattern stays visible.

---

## SF-2026-003 · Guest names, emails and phone numbers reached infrastructure logs

| | |
| --- | --- |
| **Status** | Fixed in the G5 release (staging first, then production on the user's go) |
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
