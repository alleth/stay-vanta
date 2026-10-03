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

## Smaller items

- [ ] **phpcs backlog:** about 97 pre-existing style violations in 43 files. Clean them up, then add
  `composer cs-check` to CI so style is enforced.
- [ ] **CI action versions:** GitHub warns that `actions/checkout`, `actions/cache` and
  `actions/setup-node` v4 run on a deprecated Node version; bump them. `ubuntu-latest` moves to
  Ubuntu 26 from 19 October 2026; watch the first run after that.
- [ ] **Private database networking:** the API reaches MySQL through Railway's public proxy
  (`MYSQL_PUBLIC_URL`). The private `MYSQL_URL` would avoid proxy egress; try it on staging first.
