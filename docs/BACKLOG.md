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

## CI gate on deployment: unresolved (infrastructure debt)

**Status (2026-10-03): works on production, not on staging; staging not to be relied on.**
- **Production waits.** The first promotion after enabling it (`30afd87`, 08:35:53 UTC) sat in
  Railway's `WAITING` state until the production branch's CI run passed (08:37:27), then built and
  deployed. So the setting and Railway's GitHub access work; the difference is the **staging
  service**, whose deployments never enter `WAITING` (look there: its own "Wait for CI" toggle, its
  source branch settings).
- Railway's **"Wait for CI" is enabled** on the `stay-vanta` service (staging and production).
- **Deployment still begins before CI completes.** Measured on five pushes to `main`: staging was
  live 54 s to 97 s before the backend tests finished (e.g. `776c9c2`: live 08:17:42 UTC, CI done
  08:19:19). One push (`b68ce2f`) looked gated only because its uncached build happened to take as
  long as CI.
- **The gate is not considered reliable.** Until it is proven, a green CI run is the **manual
  approval point**: nothing is promoted to production (`git push origin main:production`) before
  CI for that commit has passed.

**Why it matters:** a commit that fails CI still reaches staging (it happened with `9a39ac6`, a 500
on `GET /api/booking-sources`), and a migration that deploys before its tests fail is harder to
undo (rollback restores code, not data).

**What's known:** `railway-app` is installed on the repository: it creates a check suite on each
commit (left `queued`) and posts the commit status `perceptive-creation - stay-vanta`, which goes
`pending` → `success` with its own deployment and never waits for GitHub Actions.

- [ ] Check the Railway GitHub app's permissions (github.com/settings/installations → Railway →
  Configure): can it read check runs / Actions results; is a permission request pending?
- [ ] If Railway's gate can't be made to work: deploy from CI instead. Turn off Railway's automatic
  deploy for the service, and add a final workflow job that runs only after every test passes and
  triggers the Railway deployment with a Railway token kept as a GitHub secret (same for the
  `production` branch).
- [ ] Prove the gate with a deliberately failing test on a throwaway branch wired to it: it must not
  deploy. Timing alone isn't proof (see `b68ce2f`).

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
- The staging deployment gate is tracked above ("CI gate on deployment").

## Smaller items

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
