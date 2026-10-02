# Security findings

A permanent record of security issues found in StayVanta: what was wrong, how it was found, how it
was fixed, and whether it was ever exploited. Newest first. Add an entry for every finding, even
one fixed before release, so the pattern stays visible.

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
