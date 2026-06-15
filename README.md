# StayVanta

**All-in-One Hotel & Resort Management Platform.**

A monorepo with two apps:

| Path        | Stack                                              | Role            |
| ----------- | -------------------------------------------------- | --------------- |
| `frontend/` | React 19 + Vite, React-Bootstrap, Tailwind CSS v4  | SPA (UI)        |
| `backend/`  | CakePHP 5, MySQL                                    | JSON REST API   |

- **Database:** MySQL (local: XAMPP; production: Railway)
- **Hosting:** Cloudflare (frontend → Pages); backend API deployed separately

## Quick start (local)

```bash
# 1. Backend (http://localhost:8765)
cd backend
composer install
# MySQL must be running; create the DB and migrate:
mysql -u root -e "CREATE DATABASE stay_vanta CHARACTER SET utf8mb4"
php bin/cake.php migrations migrate
php bin/cake.php create_user --name "Owner" --email owner@stayvanta.test --password secret123 --role owner
php bin/cake.php server -p 8765

# 2. Frontend (http://localhost:5173)
cd frontend
npm install
npm run dev   # proxies /api -> http://localhost:8765
```

Log in with the owner credentials you created above.

## The 3 roles

1. **owner** — adds hotels/resorts and assigns their admins (the platform operator).
2. **admin** — per-property; adds receptionists; manages the food menu & prices.
3. **receptionist** — day-to-day operator.

**Accountability is the core goal:** every stock/asset movement records the acting
receptionist, so the platform can always show who was responsible at any time.

See `CLAUDE.md` for architecture and conventions.
