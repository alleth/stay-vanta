# Deploying StayVanta

Two pieces deploy separately:

- **Frontend** (`frontend/`) → **Cloudflare Pages** (static SPA)
- **Backend API** (`backend/`) → **Railway** (Docker), with **Railway MySQL** as the database

They live in one repo, so each platform points at a subdirectory.

---

## 1. Database — Railway MySQL

1. In your Railway project: **New → Database → Add MySQL**.
2. It exposes connection variables (`MYSQL_URL`, `MYSQL_PUBLIC_URL`, `MYSQLHOST`, …). You'll reference
   `MYSQL_PUBLIC_URL` from the API service below.

---

## 2. Backend API — Railway (Docker)

Create a service from this repo, then set its **Root Directory** to `backend`
(Settings → Source). Railway will use `backend/Dockerfile` automatically (see
`backend/railway.json`).

### Environment variables (Service → Variables)

| Variable | Value | Notes |
| --- | --- | --- |
| `DATABASE_URL` | `${{MySQL.MYSQL_PUBLIC_URL}}` | **Always a reference, never a pasted URL**: a duplicated environment then reaches its own MySQL instead of production's. Takes precedence over `DB_*`. (The private `MYSQL_URL` would avoid proxy egress; untested so far, try it on staging first.) |
| `SECURITY_SALT` | *(64-char random hex)* | `php -r "echo bin2hex(random_bytes(32));"` |
| `DEBUG` | `false` | Never `true` in production. |
| `APP_FULL_BASE_URL` | `https://<your-api>.up.railway.app` | **Required** — the API blocks requests otherwise (Host-header protection). |
| `CORS_ORIGINS` | `https://<your-app>.pages.dev` | Comma-separated; the SPA's origin(s). Without this the browser blocks the SPA in prod. |
| `LOG_TO_FILES` | *(unset)* | *Optional.* With `DEBUG=false` the app logs to stderr, which Railway's log view keeps (files inside the container vanish on redeploy). Every line written during a request starts with `[req:<id>]`, the request's `X-Request-Id`: search the logs for the id a user reports. Set `true` only to log to files instead. |
| `APP_BUSINESS_TIMEZONE` | `Asia/Manila` | *Optional* (that's the default). Where the hotels are — what "today" and the report day/week/month cut-offs mean. Leave `APP_DEFAULT_TIMEZONE` unset (UTC): stored timestamps are UTC. |

> `DATABASE_URL` is a full DSN (`mysql://user:pass@host:port/db`). If you prefer
> discrete vars, set `DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE` instead.

### Migrations

The container entrypoint runs `migrations migrate` on start (idempotent). For
stricter control, leave a single instance, or set a **Pre-deploy Command**
(Settings → Deploy) to:

```
php bin/cake.php migrations migrate --no-lock
```

### First admin user

Railway has no interactive shell on the running container; create the owner via a
one-off command (Railway "Run a command", or `railway run` locally against the
service env):

```
php bin/cake.php create_user --name "Owner" --email you@example.com --password "<strong>" --role owner
```

### Health check

The API answers `GET /api/auth/me` with **401** when unauthenticated — a good sign
it's up. The root `/` returns the CakePHP welcome page.

---

## 3. Frontend — Cloudflare Pages

**Workers & Pages → Create → Pages → Connect to Git**, pick this repo, then:

| Setting | Value |
| --- | --- |
| Production branch | `production` (see §5; `main` builds the staging preview) |
| Root directory | `frontend` |
| Build command | `npm run build` |
| Build output directory | `dist` |

### Environment variable (Pages → Settings → Variables)

| Variable | Value |
| --- | --- |
| `VITE_API_BASE_URL` | `https://<your-api>.up.railway.app/api` |

`VITE_*` vars are baked in at build time, so **redeploy after changing it**. SPA
routing is handled by `frontend/public/_redirects` (`/* /index.html 200`), which
Cloudflare picks up automatically.

---

## 4. Wire the two together

1. Deploy the API first; copy its public URL.
2. Set the API's `APP_FULL_BASE_URL` to that URL and `CORS_ORIGINS` to the Pages URL.
3. Set the Pages `VITE_API_BASE_URL` to `<api-url>/api` and redeploy.
4. Open the Pages URL and log in.

### Custom domains (optional)
Point e.g. `app.stayvanta.com` → Pages and `api.stayvanta.com` → the Railway
service, then update `APP_FULL_BASE_URL`, `CORS_ORIGINS`, and `VITE_API_BASE_URL`
to the custom domains.

---

## 5. Staging and release workflow

> **Status: live since 2026-10-02.** Pushing `main` no longer touches production.

| Environment | API (Railway) | Frontend (Cloudflare Pages) |
| --- | --- | --- |
| Staging | `https://stay-vanta-staging.up.railway.app` | `https://main.stay-vanta.pages.dev` |
| Production | `https://stay-vanta-production.up.railway.app` | `https://stay-vanta.pages.dev` |

Railway project `perceptive-creation`, environments `production` and `staging`, each with its own
`stay-vanta` API service and its own `MySQL` (separate volume data).

Two long-lived branches, two environments, one direction of travel:

| Branch | Deploys to | Who commits |
| --- | --- | --- |
| `main` | **Staging**: Railway environment `staging` + the Cloudflare preview at `https://main.<project>.pages.dev` | All work lands here |
| `production` | **Production**: Railway environment `production` + the Cloudflare production URL | Nobody. It only ever fast-forwards to a `main` commit that passed staging |

### Everyday release

1. Commit and push to `main`. Railway staging and the Cloudflare `main` preview build automatically.
2. Verify on staging with a marker unique to the new version (a new string in the bundle, the new
   Railway deployment id), not a plain 200/401. Check that migrations ran (the entrypoint runs them).
3. Promote exactly what you verified:
   ```
   git push origin main:production
   ```
   This is fast-forward only; Git refuses if `production` has diverged, which is the point.
4. Verify production the same way.

Hotfixes take the same path (main → staging → promote), just faster. Never commit to `production`.

### Rules that make this safe

- **CI gates every deployment.** Railway's "Wait for CI" holds staging and production deployments
  in `WAITING` until the commit's CI run passes, and marks them `SKIPPED` when it fails (proven
  2026-10-03 with two failing runs on `main`; see `docs/BACKLOG.md`, "CI gate on deployment", still
  under observation). Still, **nothing is promoted to production until CI for that exact commit is
  green** (`gh run list --branch main`) and it has been checked on staging. A cancelled or re-run CI
  run leaves the deployment `SKIPPED`: push an empty commit to redeploy.

- **API changes are additive first.** Cloudflare and Railway deploy independently and finish at
  different times, so for a moment the new frontend can talk to the old API or the reverse. Add the
  new endpoint, release, switch the frontend, and remove the old endpoint a release later.
- **Migrations must work with the previous code.** A Railway rollback restores code, not the
  database. Add tables and nullable columns; never rename or drop in the same release that stops
  using them.
- **Staging never gets production data.** It holds guests' personal data (Data Privacy Act,
  RA 10173). Seed staging with `create_user` and made-up test data only.

### Rollback

- Backend: Railway → `production` environment → Deployments → redeploy the previous deployment.
- Frontend: Cloudflare Pages → Deployments → roll back to the previous production deployment.
- Then fix forward on `main` and promote again.

### One-time setup (in this order, so production never breaks)

1. Create the `production` branch at the current `main`:
   `git branch production main && git push -u origin production`
2. Railway, `production` environment → API service → Settings → Source → Branch: `production`.
3. Cloudflare Pages → Settings → Builds → **Production branch: `production`**. Under preview
   branches choose **Custom** and include only `main`, so other branches don't build.
4. Railway → New environment `staging` (duplicate `production`). Give it its **own empty MySQL**,
   set the API service's branch to `main`, and set its variables:
   `APP_FULL_BASE_URL` = the staging API URL, `CORS_ORIGINS` = `https://main.<project>.pages.dev`,
   a **different** `SECURITY_SALT`, `DEBUG=false`.
5. Cloudflare Pages → Settings → Variables → **Preview** environment:
   `VITE_API_BASE_URL` = `<staging-api-url>/api`.
6. Create a staging owner with `create_user` (see "First admin user") and push a small change
   to `main` to confirm staging builds while production stays put.

---

## 6. Retention cron job (G5)

The retention routine (`bin/cake retention`, `App\Privacy\RetentionRoutine`) clears sign-in device
details after 12 months and guest contact values in guest history after 24, keeping every event.
It runs once a day as its own Railway **cron service** in each environment; every API deploy also
logs a dry run (`retention --dry-run`) of what would be cleared now. Each run, dry or real, is a
`retention_runs` row.

### Intended retention-service settings

This is the source of truth for how the `retention` service is meant to be configured. It replaces
`backend/railway.retention.json` (approved 2026-10-13 as R1, deleted 2026-10-18 because Railway
ignores a custom config file on a service created after Config as Code was deprecated: it accepted
the path, recorded it, and silently deployed with `railway.json`). Whatever carries these settings
next (dashboard today, a replacement for Config as Code later: `docs/BACKLOG.md`) must keep them:

| Setting | Intended | Why |
|---|---|---|
| Schedule | `0 19 * * *` (cron times are UTC: 03:00 Manila) | once a day, outside hotel hours |
| Image | the API's (`backend/Dockerfile`, Root Directory `backend`) | one build, one codebase |
| Start command | none | the entrypoint decides: `STAYVANTA_JOB=retention` waits for the database, runs the routine and exits; no migrations, no web server |
| Restarts | never retry a failed run | one failed run is one failed run with one log; investigate, then let the next day's run complete it |
| Source | branch `main` on staging, `production` on production, **Wait for CI** on | a commit whose CI failed never reaches the job |

**What holds today:** the schedule is set in the dashboard. The restart policy comes from the
shared `backend/railway.json` (`ON_FAILURE`, 10 retries), which Railway still reads for both
services and which overrides a dashboard setting, so "never retry" isn't enforced by Railway until
`railway.json` goes (by 2026-12-01). The job makes retries harmless instead: `db_wait` turns a
sleeping database into a wait rather than a failed start, and a failed start records nothing; and a
full real run happens **once per hotel day** (a named lock, then a check of `retention_runs`), so a
restart, a deploy or a Run now after the day's run is a logged no-op. That second guard is what
actually stopped the duplicate seen on 2026-10-18 (two deployments, each running the job once).

### Set up (once per environment, staging first)

1. **New → GitHub Repo** → this repo; name the service `retention`.
2. **Settings → Source:** Root Directory `backend`; branch `main` on staging, `production` on
   production (the same branch as that environment's `stay-vanta` API). Turn on **Wait for CI**
   (R2): a commit whose CI failed never reaches the job, as for the API.
3. **Settings → Config-as-code:** leave the Railway Config File **empty** (Railway ignores it on this
   service). **Settings → Deploy → Cron Schedule:** `0 19 * * *`. Leave the start command empty.
   Settings are staged until applied, and applying deploys the service.
4. **Variables:** `STAYVANTA_JOB=retention`, plus the API's database and app variables as
   **references**, never literals: `DATABASE_URL=${{MySQL.MYSQL_PUBLIC_URL}}`,
   `SECURITY_SALT=${{stay-vanta.SECURITY_SALT}}`, `DEBUG=false`,
   `APP_FULL_BASE_URL=${{stay-vanta.APP_FULL_BASE_URL}}`.
5. **Every deployment of this service starts the job** (seen on 2026-10-18 without a schedule): the
   first one performs the hotel day's run, later ones that day are no-ops.

### What a run logs

- `db_wait: ready after 0.2s (1 attempt(s))`; while a sleeping database wakes, first
  `db_wait: not ready (attempt n): <reason>` lines (info, no stack trace). After 90 s without an
  answer: `db_wait: gave up …`, exit 1, and nothing recorded.
- A run: one line per policy (`0 row(s) cleared` until October 2027, when the first values fall
  due) and one check line per policy (`0 past the cutoff`).
- A duplicate start: `retention: hotel day YYYY-MM-DD already covered by run retention-…; skipping`
  or `retention: another run is in progress; skipping`, exit 0, nothing recorded. **Run now on a
  day already covered is therefore a no-op by design**; there is no force option (a missed or
  interrupted day is completed by the next day's run).

### Verification

Order (R3): staging first, then production; in each, verify the schedule, then the first scheduled
run, then a same-day Run now.

- **Schedule applied:** the service shows `cronSchedule = 0 19 * * *` and a next run at 19:00 UTC;
  the applied change is in the environment's history; the deployment's resolved file config has
  `configFile: null` and no `deploy.cronSchedule` (so `railway.json` isn't overriding it).
- **Scheduled run** (starts at 19:00 UTC with no deploy): exits 0; `db_wait` lines are info only;
  both policies report `0 row(s) cleared` and both checks `0 past the cutoff`; exactly one new pair
  of real rows (`dry_run = 0`, `property_id` NULL) sharing one run id, and only one real run for that
  Manila day; no migration, Apache, warning, error or fallback lines.
- **Same-day Run now:** logs the "already covered" line, exits 0, and adds no `retention_runs` row.

## 7. Subscription enforcement (G6)

The enforcement phase is set **in the app**, by the Platform Owner on Subscribers → Enforcement,
with a reason; every change is recorded (`platform_setting_events`) with who, when, why and the
request id. Leave `APP_SUBSCRIPTION_ENFORCEMENT` **unset**.

`APP_SUBSCRIPTION_ENFORCEMENT` is an **emergency ceiling** only, for when the app itself can't be
used: set it (`report`, `grace`, `read_only` or `suspend`) on the `stay-vanta` service and redeploy;
it lowers the phase in force and can never raise it. At start-up the app records the new value as
`enforcement_ceiling_observed`: the time it was observed, not when or by whom it was set (Railway's
own activity log has that). Remove it again once the app is usable, and lower the phase there with
a reason. Each start-up logs the self-check (`enforcement: phase …, in force …; history consistent`).

## Local development (unchanged)

`config/app_local.php` (git-ignored) overrides the env-driven defaults locally, so
none of the above affects your XAMPP setup. See `README.md` / `CLAUDE.md`.

## Support: tracing a request

Every error alert in the app shows a **Reference** (the first 8 characters of the request's
`X-Request-Id`, with a Copy button for the full id). When a user reports one:

1. Search the service's logs in Railway (staging or production) for the reference.
2. The application line, `info:`/`error: [req:<id>] <status> <method> <path>: <message>`, says
   what went wrong; refusals (4xx) are one `info` line, real failures an `error` with a trace.
3. The Apache access line ends with `req:<id>`, so successful requests can be found the same way.
4. What a request changed is the set of events with that `correlation_id`
   (`ActivityIndexTable::forCorrelation()`); a Manager-facing view of it comes with the event feed
   (steps 6-8).

## Troubleshooting

- **Pages build: `npm ci ... package.json and package-lock.json not in sync`
  (`Missing: @emnapi/*`).** Cloudflare's build image bundles **npm 10**, which records
  optional transitive deps differently from newer npm. Regenerate the lockfile with the
  matching npm: `cd frontend && rm -f package-lock.json && npx npm@10.9.2 install`, verify
  with `npx npm@10.9.2 ci --dry-run`, then commit `package-lock.json`.
- **Build keeps using an old commit.** "Retry deployment" on Railway *and* Cloudflare re-runs
  the original commit. To deploy new code, push a commit (auto-deploys the new HEAD) or use
  "Create deployment", and check the newest entry's commit hash — don't click Retry.
- **Railway: "Free plan deployments must be serverless. Please go to your service settings
  and turn on the serverless flag."** Railway's free plan requires services to run in
  serverless (scale-to-zero) mode. Fix: Service → **Settings → Deploy → Serverless** → turn it
  on, then redeploy via the command palette (`Cmd/Ctrl+K`) → **"Deploy latest commit"** — a
  plain "Retry" on the failed deployment doesn't reliably pick up the flag. Safe for this app:
  it's a stateless JSON API (bearer-token auth, no sessions, no WebSockets), so scale-to-zero
  doesn't break anything — just expect the first request after idle to be slow (cold start),
  and note `docker/entrypoint.sh` re-runs `migrations migrate` on every cold start, which is
  already idempotent.
