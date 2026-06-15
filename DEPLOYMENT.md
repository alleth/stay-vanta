# Deploying StayVanta

Two pieces deploy separately:

- **Frontend** (`frontend/`) → **Cloudflare Pages** (static SPA)
- **Backend API** (`backend/`) → **Railway** (Docker), with **Railway MySQL** as the database

They live in one repo, so each platform points at a subdirectory.

---

## 1. Database — Railway MySQL

1. In your Railway project: **New → Database → Add MySQL**.
2. It exposes connection variables (`MYSQL_URL`, `MYSQLHOST`, …). You'll reference
   `MYSQL_URL` from the API service below.

---

## 2. Backend API — Railway (Docker)

Create a service from this repo, then set its **Root Directory** to `backend`
(Settings → Source). Railway will use `backend/Dockerfile` automatically (see
`backend/railway.json`).

### Environment variables (Service → Variables)

| Variable | Value | Notes |
| --- | --- | --- |
| `DATABASE_URL` | `${{MySQL.MYSQL_URL}}` | Reference the MySQL service; takes precedence over `DB_*`. |
| `SECURITY_SALT` | *(64-char random hex)* | `php -r "echo bin2hex(random_bytes(32));"` |
| `DEBUG` | `false` | Never `true` in production. |
| `APP_FULL_BASE_URL` | `https://<your-api>.up.railway.app` | **Required** — the API blocks requests otherwise (Host-header protection). |
| `CORS_ORIGINS` | `https://<your-app>.pages.dev` | Comma-separated; the SPA's origin(s). Without this the browser blocks the SPA in prod. |

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
| Production branch | `main` |
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

## Local development (unchanged)

`config/app_local.php` (git-ignored) overrides the env-driven defaults locally, so
none of the above affects your XAMPP setup. See `README.md` / `CLAUDE.md`.
