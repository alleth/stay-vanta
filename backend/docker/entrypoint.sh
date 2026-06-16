#!/bin/sh
set -e

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
# tolerate Railway's private network taking a few seconds at boot.
n=0
until php bin/cake.php migrations migrate --no-lock; do
  n=$((n + 1))
  if [ "$n" -ge 10 ]; then
    echo "WARN: migrations did not run after ${n} attempts — check DATABASE_URL"
    break
  fi
  echo "DB not ready, retrying migrations in 3s (${n}/10)..."
  sleep 3
done

exec apache2-foreground
