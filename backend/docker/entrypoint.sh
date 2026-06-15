#!/bin/sh
set -e

# Railway injects $PORT; fall back to 8080 for local `docker run`.
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/__PORT__/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Apply database migrations (idempotent — only new migrations run). Railway's
# private network can take a few seconds to come up at boot, so retry before
# giving up rather than starting with an un-migrated schema. A persistent
# failure here usually means DATABASE_URL is wrong (see DEPLOYMENT.md).
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
