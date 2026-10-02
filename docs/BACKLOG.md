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

## Smaller items

- [ ] **phpcs backlog:** about 97 pre-existing style violations in 43 files. Clean them up, then add
  `composer cs-check` to CI so style is enforced.
- [ ] **CI action versions:** GitHub warns that `actions/checkout`, `actions/cache` and
  `actions/setup-node` v4 run on a deprecated Node version; bump them. `ubuntu-latest` moves to
  Ubuntu 26 from 19 October 2026; watch the first run after that.
- [ ] **Private database networking:** the API reaches MySQL through Railway's public proxy
  (`MYSQL_PUBLIC_URL`). The private `MYSQL_URL` would avoid proxy egress; try it on staging first.
