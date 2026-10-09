#!/bin/sh
set -e

# A job container (a Railway cron service with STAYVANTA_JOB set) runs that
# one job and exits: no migrations (the API service runs those) and no web
# server. `retention` is the daily retention routine (G5, P5).
if [ "${STAYVANTA_JOB:-}" = "retention" ]; then
  exec php bin/cake.php retention
fi

# Railway injects $PORT; fall back to 8080 for local `docker run`.
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/__PORT__/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# --- Force a single Apache MPM (prefork) at RUNTIME ----------------------
# The build-time fix didn't always stick, so normalise here too and print
# diagnostics so the deploy log shows exactly what loads an MPM.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null || true
a2enmod mpm_prefork >/dev/null 2>&1 || true
echo "MPM-DIAG mods-enabled:"; ls /etc/apache2/mods-enabled/ | grep -i mpm || echo "  (none)"
echo "MPM-DIAG LoadModule refs:"; grep -rEin "LoadModule[[:space:]]+mpm" /etc/apache2/ 2>/dev/null || echo "  (none)"

# Apply database migrations (idempotent — only new migrations run). Retry to
# tolerate Railway's private network taking a few seconds at boot. If the DB is
# still unreachable after the budget, EXIT non-zero rather than serving traffic:
# starting Apache with a stale schema silently 500s every request that touches a
# new column. A hard exit makes Railway restart the container (giving the DB more
# time) and surfaces the failure in the deploy logs instead of hiding it.
n=0
max=20
until php bin/cake.php migrations migrate --no-lock; do
  n=$((n + 1))
  if [ "$n" -ge "$max" ]; then
    echo "FATAL: migrations did not run after ${n} attempts — check DATABASE_URL. Aborting boot."
    exit 1
  fi
  echo "DB not ready, retrying migrations in 3s (${n}/${max})..."
  sleep 3
done

# Retention (G5, P5): the daily Railway cron job clears expired personal
# values; each deploy logs what it would clear now (a dry run, recorded in
# retention_runs). Never blocks the boot.
php bin/cake.php retention --dry-run || true

# Migrations run as root, and data migrations load tables, so the schema
# cache now holds root-owned files that Apache (www-data) can't open
# ("Permission denied" warnings), possibly describing a table a later
# migration changed. Forget them and give tmp/ back to Apache.
rm -f tmp/cache/models/* 2>/dev/null || true
chown -R www-data:www-data tmp logs 2>/dev/null || true

exec apache2-foreground
