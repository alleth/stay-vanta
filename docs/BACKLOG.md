# Backlog

Agreed follow-up work that isn't on the current build order. Each item says when it becomes
ready, so it isn't started too early.

## Content review after the terminology settles

**Ready when:** the naming release (build step 3) is in production and its terms have been in use
for a while, so screenshots and wording only need redoing once.

**Why it's deferred:** step 3 changes the application only. Marketing and help content was left
unchanged on purpose (decided 2026-10-02) to keep that release focused and low-risk.

Review and update together, as one release:
- [ ] Landing page (`frontend/src/pages/Landing.jsx`): still says "Dashboard" and "revenue"
- [ ] Terms of Service (`frontend/src/pages/TermsOfService.jsx`)
- [ ] Privacy Policy (`frontend/src/pages/PrivacyPolicy.jsx`)
- [ ] Help documentation
- [ ] Screenshots
- [ ] User guides and onboarding / training material

Use the terminology change log of the naming release as the source for every renamed term:
Operations, Finance, POS, Platform Owner / Manager / Front Desk Staff, Collected, Outstanding,
Receivables, Vacant, Not billed / Billed / Settled, Post room charge.

## Platform Owner access to hotel data

**Ready when:** Permissions Phase 2 (build step 10) introduces memberships and turns the Platform
Owner into a platform flag. Phase 1 keeps today's behavior unchanged on purpose.

**What:** the Platform Owner (`users.property_id` null) passes every hotel endpoint that has no
role check, plus every `owner, admin` check on hotel configuration. `scopeToProperty()` applies no
filter when no `property_id` is passed, so `GET /api/guests` returns every hotel's guests and
`POST /api/invoices/{id}/settle` reaches any hotel's invoice. The screens show the Platform Owner
only Dashboard and Subscribers. The `PO†` grants in `docs/PERMISSIONS.md` mark every case.

**Why it's here, not in SECURITY-FINDINGS:** the behavior is intentional (support access by the
platform operator), not an accident. It's an architectural concern, decided 2026-10-03, and
unrestricted access must not stay permanent.

Direction:
- [ ] Platform access is separate from property permissions: the platform flag grants only
  `platform.*`.
- [ ] "No property" never means "all properties". A null property id grants nothing outside
  explicit platform endpoints.
- [ ] Support access to a hotel is deliberate: started explicitly, scoped to one property, time
  limited, and recorded with actor, reason and timestamp in `access_events`.

## CI gate on deployment: resolved (under observation)

**Status (2026-10-03): working on staging and production.** Railway's "Wait for CI" is enabled on
the `stay-vanta` service in both environments, and both now hold deployments back:
- **A failing run blocks the deploy.** `961e850` and `d7352c0` failed CI on `main` (a migration
  ordering bug, see CLAUDE.md "migrations must work on a fresh DB in order"): Railway marked both
  deployments `SKIPPED` and staging stayed on the previous good build. This is the proof by a
  failing run that the item asked for.
- **A passing run deploys after CI.** `390a888` sat in `WAITING` until its CI run passed, then
  built and deployed. Production has waited the same way since `30afd87`.
- Earlier the same day staging went live 54 s to 97 s before CI finished (five pushes, e.g.
  `776c9c2`); that stopped once the setting took effect on the staging service.

**Keep observing:** after each push, check that staging's deployment shows `WAITING` before CI
finishes. If a failing commit ever reaches staging again, reopen this item; the fallback plan is to
deploy from CI (Railway auto-deploy off, a final workflow job triggers the deployment with a
Railway token kept as a GitHub secret).

**Note:** a CI run that is cancelled or re-run (a GitHub hiccup) leaves Railway's deployment
`SKIPPED` even when the re-run is green; push an empty commit to redeploy.

## Step 7 agenda: refunds, credit notes, adjustments and what "Collected" means

**Ready when:** step 7 (the shared collections calculation) starts. Not before: finance semantics
don't change during step 6 (decided 2026-10-03).

**Why:** settled invoices are immutable from step 6, with **one documented exception**: the
downpayment refund on cancelling an advance booking adds a negative line to the downpayment's
settled invoice (recorded as `refund_recorded` in `invoice_events`). Its effect is that the refund
lowers Collected on the day the downpayment was collected, not the day the money went back.

**Decided 2026-10-03 (7b review, D1–D8):** Collected = cash in, Refunded = cash out, Net Collected
= the difference, each on the day the money moved; refunds are their own events (ending the
exception); pre-7c downpayment refunds restated once to their own day; cancelled paid POS sales
with no refund on record stay collected; refund method recorded; no backdating. Manager refunds
on settled invoices (`finance.invoice.refund`) approved as S1.
- [x] 7a: one shared calculation (`App\Model\Finance\Collections`), figures unchanged.
- [x] 7c-1: `method` columns, the cash-movement calculation beside today's, the restatement list
  logged on deploy (`bin/cake cash_restatement`). No figure changes.
- [x] 7c-2: the switch (refund events, UI, reports), `APP_COLLECTED_MODEL=cash|historical` for
  one release. In production 2026-10-04 (`385b5b0`); restatement at the switch: nothing moved.
- [ ] Cleanup release: remove `APP_COLLECTED_MODEL` / `Collections::historical()` and the two
  log-only restatement migrations' command once production has run a release on `cash` without
  a rollback (keep the pre-7c `downpayment_refund` line handling: that history stays).
- [ ] Settlement and downpayment collection record no payment method (refunds do): add
  `method` to `settled` / `settled_on_creation` so cash in can be reconciled by method too.
- [ ] A mistaken refund can't be corrected yet: needs a `refund_corrected` event referencing the
  original (append-only), Manager + reason.
- [ ] Deferred: credit notes (need a credit-memo booklet series) and voids (not built).

## Findings from the event foundation (step 5, decided 2026-10-03)

Carry these into steps 6–10:

- [ ] **Backfills report added and skipped counts**, per table and property, in the log line, not
  only the final check. (`BackfillActivityIndex` discards what `ActivityBackfill::run()` returns,
  so its additions had to be worked out from the check.)
- [ ] **An operational workflow for maintenance commands** on Railway. `bin/cake activity_backfill`,
  later invoice/reservation backfills and privacy redaction need a sanctioned way to run against
  staging and production (a one-off job, or `railway ssh` with an approved key), not a local run
  pointed at a Railway database.
- [ ] **Event feeds keep one business action together:** events that share a correlation id stay
  grouped and in recorded order (`ActivityIndexTable::forCorrelation()`).
- [ ] **`occurred_at` is the authoritative time** of an event. The step 5 feed still shows each
  record's `created` (same second today); the event feed shows `occurred_at`. For a backdated stay
  that's when it was recorded, not the stay's dates.
- The deployment gate (resolved, under observation) is described above ("CI gate on deployment").

## Smaller items

- [ ] **Staging database sleeps when idle (observed 2026-10-04, not a blocker).** Railway stops the
  staging MySQL container about 10 minutes after its last activity ("Received SHUTDOWN from user
  <via user signal>") and wakes it on the next request, which takes about 45 s; requests in that
  window get 500 "MySQL server has gone away". It cost one false alarm during the 7c-1 check (every
  money endpoint 500, rerun clean once MySQL was ready). Either turn off sleeping for the staging
  MySQL service, or before a staging comparison wake it and wait for "ready for connections" in its
  log. Production has shown no sleeping.

- [ ] **Staff accounts can name a property that doesn't exist.** `POST /api/users` as the Platform
  Owner accepts any `property_id` (found 2026-10-03: a timed-out property creation left
  `property_id` 0 in a script, and two staging accounts were created bound to it; they're
  deactivated). `UsersTable` has no `existsIn` rule for `property_id`. Add one (Platform Owner
  only today, so no isolation risk: such a user sees nothing). Phase 2 memberships replace the
  column anyway.

- [ ] **phpcs backlog:** about 97 pre-existing style violations in 43 files. Clean them up, then add
  `composer cs-check` to CI so style is enforced.
- [ ] **CI action versions:** GitHub warns that `actions/checkout`, `actions/cache` and
  `actions/setup-node` v4 run on a deprecated Node version; bump them. `ubuntu-latest` moves to
  Ubuntu 26 from 19 October 2026; watch the first run after that.
- [ ] **Private database networking:** the API reaches MySQL through Railway's public proxy
  (`MYSQL_PUBLIC_URL`). The private `MYSQL_URL` would avoid proxy egress; try it on staging first.
