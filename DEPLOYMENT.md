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

- **CI success is the manual approval point.** On production, Railway's "Wait for CI" works (a
  promotion shows `WAITING` until CI passes, seen 2026-10-03), but staging's does **not**
  hold deployments back (measured 2026-10-03: staging goes live about a minute before CI finishes;
  see `docs/BACKLOG.md`, "CI gate on deployment"). So a commit reaches staging whether or not its
  tests pass, and **nothing is promoted to production until CI for that exact commit is green**
  (`gh run list --branch main`). Treat the gate as unresolved until it's proven with a failing run.

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

## Local development (unchanged)

`config/app_local.php` (git-ignored) overrides the env-driven defaults locally, so
none of the above affects your XAMPP setup. See `README.md` / `CLAUDE.md`.

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
